<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Subscription;
use App\Services\PaystackClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SyncSubscriptions extends Command
{
    protected $signature = 'subscriptions:sync';
    protected $description = 'Daily sync and expiry check for subscriptions';

    public function handle(PaystackClient $paystack)
    {
        $now = Carbon::now();

        // Evaluate dates through the shared entitlement predicate so admin-granted
        // extensions are not expired by the provider billing date.
        $expired = Subscription::whereIn('status', ['active', 'past_due'])
            ->with('user')
            ->get()
            ->filter(fn (Subscription $subscription) => !$subscription->hasAccessAt($now));

        foreach ($expired as $subscription) {
            $subscription->status = 'expired';
            $subscription->save();

            $user = $subscription->user;
            if ($user) {
                $hasAnotherEntitledSubscription = Subscription::query()
                    ->where('userId', $user->id)
                    ->where('subscriptionId', '!=', $subscription->subscriptionId)
                    ->whereIn('status', ['active', 'past_due'])
                    ->get()
                    ->contains(fn (Subscription $other) => $other->hasAccessAt($now));

                if (!$hasAnotherEntitledSubscription) {
                    $user->update(['currentPlan' => 1]);
                }
            }

            Log::channel('daily')->info("Expired subscription ID: {$subscription->subscriptionId}");
        }

        $paystackSubscriptionsToDisable = Subscription::query()
            ->where('provider', 'paystack')
            ->where('status', 'expired')
            ->whereNotNull('providerSubscriptionId')
            ->whereNotNull('providerSubscriptionEmailToken')
            ->whereNull('providerSubscriptionDisabledAt')
            ->get();

        foreach ($paystackSubscriptionsToDisable as $subscription) {
            try {
                $response = $paystack->disableSubscription(
                    $subscription->providerSubscriptionId,
                    $subscription->providerSubscriptionEmailToken
                );

                if ($response->successful()) {
                    $subscription->update(['providerSubscriptionDisabledAt' => $now]);
                } else {
                    Log::warning('Paystack subscription disable failed after grace expiry', [
                        'subscription_id' => $subscription->subscriptionId,
                        'provider_status' => $response->status(),
                    ]);
                }
            } catch (\Throwable $exception) {
                Log::warning('Paystack subscription disable request failed after grace expiry', [
                    'subscription_id' => $subscription->subscriptionId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        Log::channel('daily')->info("Subscription sync completed.");
    }
}
