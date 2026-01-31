<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Subscription;
use App\Models\Payment;

class WebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Verify signature
       $signature = $request->header('verif-hash');

    if (!$signature || $signature !== env('FLUTTERWAVE_WEBHOOK_SECRET')) {
        \Log::warning('Invalid Flutterwave webhook signature');
        return response('Unauthorized', 401);
    }

    // Now process the payload safely
    $payload = $request->all();
    \Log::info('Flutterwave webhook received', $payload);

        $event = $request->input('event');
        $data = $request->input('data');

        Log::info('Flutterwave Webhook', ['event' => $event, 'data' => $data]);

        if ($event === 'charge.completed' && $data['status'] === 'successful') {
    // Correct way to get your custom meta
    // $subscriptionId = $data['meta_data']['subscriptionId'] ?? null;
    $meta = $request->input('meta_data', []);
$subscriptionId = $meta['subscriptionId'] ?? null;


    // Also fallback to tx_ref pattern (more reliable if meta missing)
    if (!$subscriptionId) {
        $txRef = $data['tx_ref'] ?? '';
        preg_match('/sub-(\d+)/', $txRef, $matches);
        $subscriptionId = $matches[1] ?? null;
    }

    $sub = Subscription::where('subscriptionId', $subscriptionId)->first();

    if ($sub) {
        Log::info('Found subscription, activating', ['subscriptionId' => $subscriptionId]);

        // Activate subscription
        $sub->update([
            'status' => 'active',
            'flutterwaveSubscriptionId' => $data['id'],
            'startDate' => now(),
            'nextBillingDate' => now()->addMonth(),
            'metadata' => $data,
        ]);

        // Update user's current plan
        $sub->user->update(['currentPlan' => $sub->planId]);

        // Record the payment
        Payment::create([
            'subscriptionId' => $sub->subscriptionId,
            'flutterwaveTxRef' => $data['tx_ref'],
            'flutterwaveTxId' => $data['id'],
            'amount' => $data['charged_amount'] ?? $data['amount'],
            'currency' => $data['currency'],
            'status' => 'successful',
            // 'paidAt' => now(),
            'responseData' => json_encode($data),
        ]);

        Log::info('Subscription activated and payment recorded', ['subscriptionId' => $subscriptionId]);
    } else {
        Log::warning('Subscription not found for webhook', ['subscriptionId' => $subscriptionId, 'tx_ref' => $data['tx_ref']]);
    }

        } 
        elseif ($event === 'subscription.cancelled' || $event === 'subscription.disabled') {
    // Flutterwave uses these events for cancellation
    $flutterwaveSubId = $data['id'] ?? null; // This is Flutterwave's subscription ID

    $sub = Subscription::where('flutterwaveSubscriptionId', $flutterwaveSubId)->first();

    if ($sub) {
        $sub->update([
            'status' => 'cancelled',
            'endDate' => now(),
        ]);

        $sub->user->update(['plan_id' => 1]); // Free plan
        Log::info('Subscription cancelled via webhook', ['subscriptionId' => $sub->subscriptionId]);
    }

        }

        // Add handlers for other events (e.g., charge.failed)

        return response()->json(['status' => 'success'], 200);
    }



//     public function handle(Request $request)
// {
//     $signature = $request->header('verif-hash');

//     // Log for debugging (remove in production if you want)
//     \Log::info('Flutterwave webhook attempt', [
//         'received_signature' => $signature,
//         'expected' => env('FLUTTERWAVE_WEBHOOK_SECRET'),
//         'ip' => $request->ip(),
//     ]);

//     if (!$signature || $signature !== env('FLUTTERWAVE_WEBHOOK_SECRET')) {
//         \Log::warning('Invalid or missing Flutterwave webhook signature');
//         return response('Unauthorized', 401);
//     }

//     // Now it's safe — process the payload
//     $payload = $request->all();

//     \Log::info('Valid Flutterwave webhook received', $payload);

//     // Handle the event
//     if ($payload['event'] === 'charge.completed' && $payload['data']['status'] === 'successful') {
//         // Extract your subscription ID from tx_ref
//         $txRef = $payload['data']['tx_ref'];
//         preg_match('/sub-(\d+)/', $txRef, $matches);
//         $subscriptionId = $matches[1] ?? null;

//         if ($subscriptionId) {
//             $subscription = Subscription::find($subscriptionId);
//             if ($subscription && $subscription->status === 'pending') {
//                 $subscription->update([
//                     'status' => 'active',
//                     'flutterwaveTransactionId' => $payload['data']['id'],
//                     'chargedAmount' => $payload['data']['amount'],
//                     'chargedCurrency' => $payload['data']['currency'],
//                 ]);
//             }
//         }
//     }

//     // Always return 200 quickly
//     return response('OK', 200);
// }



}