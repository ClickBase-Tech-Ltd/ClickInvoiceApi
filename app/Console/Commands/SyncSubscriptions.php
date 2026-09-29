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

        // 1️⃣ Expire local subscriptions that have passed explicit endDate
        // or have reached their nextBillingDate when endDate is missing.
        $expired = Subscription::whereIn('status', ['active', 'past_due'])
            ->where(function ($q) use ($now) {
                $q->where(function ($active) use ($now) {
                    $active->where('status', 'active')
                        ->where(function ($dates) use ($now) {
                            $dates->where(function ($endDate) use ($now) {
                                $endDate->whereNotNull('endDate')->where('endDate', '<=', $now);
                            })->orWhere(function ($nextBilling) use ($now) {
                                $nextBilling->whereNull('endDate')
                                    ->whereNotNull('nextBillingDate')
                                    ->where('nextBillingDate', '<=', $now);
                            });
                        });
                })->orWhere(function ($grace) use ($now) {
                    $grace->where('status', 'past_due')
                        ->whereNotNull('endDate')
                        ->where('endDate', '<=', $now);
                });
            })
            ->get();

        foreach ($expired as $subscription) {
            $subscription->status = 'expired';
            $subscription->save();

            $user = $subscription->user;
            if ($user) {
                $hasAnotherEntitledSubscription = Subscription::query()
                    ->where('userId', $user->id)
                    ->where('subscriptionId', '!=', $subscription->subscriptionId)
                    ->where(function ($query) use ($now) {
                        $query->where(function ($active) use ($now) {
                            $active->where('status', 'active')
                                ->where(function ($dates) use ($now) {
                                    $dates->whereNull('startDate')->orWhere('startDate', '<=', $now);
                                })
                                ->where(function ($dates) use ($now) {
                                    $dates->whereNull('endDate')->orWhere('endDate', '>', $now);
                                })
                                ->where(function ($dates) use ($now) {
                                    $dates->whereNull('nextBillingDate')->orWhere('nextBillingDate', '>', $now);
                                });
                        })->orWhere(function ($grace) use ($now) {
                            $grace->where('status', 'past_due')
                                ->whereNotNull('endDate')
                                ->where('endDate', '>', $now);
                        });
                    })
                    ->exists();

                if (!$hasAnotherEntitledSubscription) {
                    $user->update(['currentPlan' => 1]);
                }
            }

            // $this->info("Expired subscription ID: {$subscription->subscriptionId}");
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
