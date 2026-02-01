<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Subscription;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SyncSubscriptions extends Command
{
    protected $signature = 'subscriptions:sync';
    protected $description = 'Daily sync and expiry check for subscriptions';

    public function handle()
    {
        $now = Carbon::now();

        // 1️⃣ Expire local subscriptions that have passed end date
        $expired = Subscription::where('status', 'active')
            ->where('endDate', '<', $now)
            ->get();

        foreach ($expired as $subscription) {
            $subscription->status = 'expired';
            $subscription->save();

            // $this->info("Expired subscription ID: {$subscription->subscriptionId}");
            Log::channel('daily')->info("Expired subscription ID: {$subscription->subscriptionId}");
    
        }

        // 2️⃣ (Optional but recommended) Reconcile with Flutterwave
        $activeSubs = Subscription::whereIn('status', ['active', 'expired'])
            ->whereNotNull('flutterwaveSubscriptionId')
            ->get();

        foreach ($activeSubs as $subscription) {
            $this->syncWithFlutterwave($subscription);
        }

        // $this->info('Subscription sync completed.');
        Log::channel('daily')->info("Subscription sync completed.");

    }

   private function syncWithFlutterwave(Subscription $subscription)
{
    // 1️⃣ Check if Flutterwave subscription ID exists
    if (!$subscription->flutterwaveSubscriptionId) {
        Log::channel('daily')->error("No Flutterwave Subscription ID for subscription {$subscription->subscriptionId}");
        return;
    }

    Log::info("FlutterwaveSubscriptionId: {$subscription->flutterwaveSubscriptionId}");
    $secretKey = env('FLUTTERWAVE_SECRET_KEY');

    $getUserEmail = $subscription->user ? $subscription->user->email : 'unknown';
    Log::info("Fetching Flutterwave subscription for user: {$getUserEmail}");
    // 2️⃣ Fetch subscription(s) from Flutterwave using the subscription ID
    $response = Http::withHeaders([
            'Authorization' => "Bearer $secretKey"
        ])->get("https://api.flutterwave.com/v3/subscriptions?email={$getUserEmail}");

    // 3️⃣ Log raw response for debugging
    Log::info('Flutterwave response', ['raw' => $response->body()]);

    if (!$response->successful()) {
        $this->error("Failed Flutterwave sync for subscription {$subscription->subscriptionId}");
        Log::error("Failed Flutterwave sync for subscription {$subscription->subscriptionId}", [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
        return;
    }

    // 4️⃣ Get the list of subscriptions from response
    $subscriptions = $response->json('data', []); // defaults to empty array if 'data' is missing
    Log::info('Flutterwave subscriptions data', ['subscription' => $subscription, 'data' => $subscriptions]);
    // 5️⃣ Loop through Flutterwave subscriptions (usually only one, but API returns an array)
    foreach ($subscriptions as $fwSub) {
        $flutterwaveStatus = $fwSub['status'] ?? null; // active | cancelled | completed

        Log::info('Flutterwave subscription status', [
            'fw_subscription_id' => $fwSub['id'] ?? null,
            'status' => $flutterwaveStatus,
            'email' => $fwSub['customer']['email'] ?? null,
        ]);

        // 6️⃣ Flutterwave cancelled/completed → update locally
        if (in_array($flutterwaveStatus, ['cancelled', 'completed']) 
            && $subscription->status !== 'cancelled') {

            $subscription->status = 'cancelled';
            $subscription->save();

            $this->info("Subscription {$subscription->subscriptionId} cancelled via Flutterwave");
            return; // no need to continue after local update
        }

        // 7️⃣ Local expiry → cancel on Flutterwave (once)
        if (
            $subscription->status === 'expired' &&
            $flutterwaveStatus === 'active' &&
            $subscription->flutterwaveCancelledAt === null
        ) {
            $this->cancelOnFlutterwave($subscription);

            $this->info("Cancelled Flutterwave subscription for {$subscription->subscriptionId}");
        }
    }
}




private function cancelOnFlutterwave(Subscription $subscription)
{
      if ($subscription->flutterwaveCancelledAt !== null) {
        return;
    }
    if (!$subscription->flutterwaveSubscriptionId) {
        return;
    }

    Http::withHeaders(['Authorization' => "Bearer " . env('FLUTTERWAVE_SECRET_KEY')])
        ->post("https://api.flutterwave.com/v3/subscriptions/{$subscription->flutterwaveSubscriptionId}/cancel");

        $subscription->flutterwaveCancelledAt = now();
        $subscription->save();
}


}
