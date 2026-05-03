<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Plans;
use App\Models\Subscription;
use App\Models\Payment;
use App\Models\User; // Assuming auth
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{

    public function index(Request $request)
    {

        $subscriptions = Subscription::with('user', 'plan.currency_detail')->get();

        return response()->json([
            'subscriptions' => $subscriptions,
        ]);
    }

    public function mySubscriptions(Request $request)
    {
        $user = auth()->user();

        $subscriptions = Subscription::with('user', 'plan.currency_detail')
            ->where('userId', $user->id)
            ->get();

        return response()->json([
            'subscriptions' => $subscriptions,
        ]);
    }

    public function create(Request $request, $planId)
    {
        $user = auth()->user();

        // --- Guard: plan must exist ---
        $plan = Plans::with('currency_detail')->where('planId', $planId)->first();
        if (!$plan) {
            return response()->json(['error' => 'Plan not found'], 404);
        }

        // --- Guard: plan must have a Flutterwave plan ID configured ---
        if (!$plan->flutterwavePlanId) {
            return response()->json(['error' => 'This plan is not configured for online payment. Please contact support.'], 400);
        }

        // --- Guard: currency relation must be loaded ---
        if (!$plan->currency_detail) {
            Log::error('Plan currency_detail missing', ['planId' => $planId]);
            return response()->json(['error' => 'Plan currency is not configured. Please contact support.'], 500);
        }

        // --- Guard: Flutterwave key must be set ---
        $secretKey = env('FLUTTERWAVE_SECRET_KEY');
        if (!$secretKey) {
            Log::error('FLUTTERWAVE_SECRET_KEY is not set in .env');
            return response()->json(['error' => 'Payment gateway not configured. Please contact support.'], 500);
        }

        // --- Guard: duplicate active subscription on same plan ---
        $existingSub = $user->subscription;
        if ($existingSub && $existingSub->status === 'active' && $existingSub->planId == $plan->planId) {
            return response()->json(['error' => 'You already have an active subscription on this plan'], 400);
        }

        // Create a pending subscription record to track this checkout attempt
        $subscription = Subscription::create([
            'userId'                    => $user->id,
            'planId'                    => $plan->planId,
            'flutterwaveSubscriptionId' => $plan->flutterwaveSubscriptionId ?? null,
            'status'                    => 'pending',
        ]);

        $txRef = 'sub-' . $subscription->subscriptionId . '-' . time();

        // Build customer name from firstName/lastName fields
        $customerName = trim(($user->firstName ?? '') . ' ' . ($user->lastName ?? ''));
        if ($customerName === '') {
            $customerName = $user->email;
        }

        // Redirect back to the frontend subscriptions page after payment
        $frontendRedirect = rtrim(env('FRONTEND_URL', 'https://app.clickinvoice.app'), '/')
            . '/dashboard/my-subscriptions';

        // Call Flutterwave Payments API
        $response = Http::withHeaders(['Authorization' => "Bearer $secretKey"])
            ->post('https://api.flutterwave.com/v3/payments', [
                'tx_ref'       => $txRef,
                'amount'       => $plan->price,
                'currency'     => $plan->currency_detail->currencyCode,
                'payment_plan' => $plan->flutterwavePlanId,
                'redirect_url' => $frontendRedirect,
                'customer'     => [
                    'email' => $user->email,
                    'name'  => $customerName,
                ],
                'customizations' => [
                    'title'       => 'ClickInvoice – ' . $plan->planName,
                    'description' => $plan->planName . ' monthly subscription',
                    'logo'        => 'https://app.clickinvoice.app/icons/icon-192x192.png',
                ],
                'meta' => [
                    'subscriptionId' => $subscription->subscriptionId,
                    'userId'         => $user->id,
                ],
            ]);

        if ($response->successful()) {
            $responseData = $response->json();
            $link = $responseData['data']['link'] ?? null;

            if (!$link) {
                $subscription->delete();
                Log::error('Flutterwave returned success but no payment link', ['response' => $responseData]);
                return response()->json(['error' => 'Payment gateway did not return a payment link.'], 500);
            }

            // Record the pending payment
            Payment::create([
                'subscriptionId'   => $subscription->subscriptionId,
                'amount'           => $plan->price,
                'currency'         => $plan->currency_detail->currencyCode,
                'status'           => 'pending',
                'flutterwaveTxRef' => $txRef,
                'userId'           => $user->id,
            ]);

            return response()->json(['payment_link' => $link]);
        } else {
            $subscription->delete();

            $flwError = $response->json();
            Log::error('Flutterwave payment initiation failed', [
                'status'   => $response->status(),
                'response' => $flwError,
                'planId'   => $planId,
                'userId'   => $user->id,
            ]);

            $message = $flwError['message'] ?? ($flwError['error'] ?? 'Failed to initiate payment with Flutterwave.');

            return response()->json([
                'error'   => $message,
                'details' => $flwError,
            ], 502);
        }
    }


public function verifyRedirect(Request $request)
{
    $status = $request->query('status');
    $txRef = $request->query('tx_ref');
    $transactionId = $request->query('transaction_id');

    // Optional: Extra security - verify with Flutterwave
    if ($status === 'successful' && $transactionId) {
        $response = Http::withToken(env('FLUTTERWAVE_SECRET_KEY'))
            ->get("https://api.flutterwave.com/v3/transactions/{$transactionId}/verify");

        if ($response->successful() && $response->json('data.status') === 'successful') {
            // Safe to show success (webhook will have already updated DB)
            return redirect(env('FRONTEND_URL') . "/subscription/success?tx_ref={$txRef}");
        }
    }

    // Failed, cancelled, or verification failed
    return redirect(env('FRONTEND_URL') . "/subscription/failed?reason={$status}");
}

    // Handle redirect after payment (optional: can be frontend page that polls backend or shows success)
    public function redirect(Request $request)
    {
        $status = $request->query('status');
        $txRef = $request->query('tx_ref');
        $txId = $request->query('transaction_id');

        if ($status === 'successful') {
            // Verify immediately or let webhook handle
            // Redirect to frontend success page
            return redirect('http://your-frontend.com/plans?success=1');
        } else {
            return redirect('http://your-frontend.com/plans?error=1');
        }
    }


    public function cancel(Request $request)
{
    $user = auth()->user();
   return $sub = $user->subscription;

    if (!$sub || $sub->status !== 'active') {
        return response()->json(['error' => 'No active subscription'], 400);
    }

    $secretKey = env('FLUTTERWAVE_SECRET_KEY');
    $response = Http::withHeaders(['Authorization' => "Bearer $secretKey"])
        ->put("https://api.flutterwave.com/v3/subscriptions/{$sub->flutterwave_subscription_id}/cancel");

    if ($response->successful()) {
        $sub->update(['status' => 'cancelled', 'endDate' => now()]);
        $user->update(['planId' => 1]); // Downgrade
        return response()->json(['success' => true]);
    } else {
        return response()->json(['error' => 'Failed to cancel'], 500);
    }
}

public function activate(Request $request, $subscriptionId)
    {
        $subscription = Subscription::where('subscriptionId', $subscriptionId)->first();

        // Optional validation
        $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        // Check if already active
        if ($subscription->status === 'active') {
            return response()->json(['message' => 'Subscription is already active'], 400);
        }

        // Activate
        $now = Carbon::now();
        $subscription->status = 'active';
        $subscription->startDate = $now;
        $subscription->nextBillingDate = $now->addMonth(); // Assuming monthly billing; adjust if different interval
        $subscription->endDate = null; // Clear end date if any
        $subscription->metadata = array_merge($subscription->metadata ?? [], [
            'manual_activation' => true,
            'activation_reason' => $request->reason,
            'activated_at' => $now->toDateTimeString(),
        ]);
        $subscription->save();

        // Optional: Update user's current_plan
        $subscription->user->update(['currentPlan' => $subscription->planId]);

        // If Flutterwave integration needed, but since manual, perhaps skip or simulate

        Log::info("Subscription {$subscription->subscriptionId} manually activated", ['user_id' => $subscription->userId]);
        return response()->json([
            'message' => 'Subscription activated successfully',
            'subscription' => $subscription->fresh(['user', 'plan.currency_detail']),
        ]);
    }

    // PATCH /subscriptions/{id}/deactivate
    public function deactivate(Request $request, $subscriptionId)
    {
        $subscription = Subscription::where('subscriptionId', $subscriptionId)->first();

        // Optional validation
        $request->validate([
            'reason' => 'nullable|string|max:255',
            'immediate' => 'boolean',
        ]);

        // Check if not active
        if ($subscription->status !== 'active') {
            return response()->json(['message' => 'Subscription is not active'], 400);
        }

        $now = Carbon::now();

        // If Flutterwave ID exists, cancel via API
        if ($subscription->flutterwaveSubscriptionId) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('FLUTTERWAVE_SECRET_KEY'),
                ])->patch("https://api.flutterwave.com/v3/subscriptions/{$subscription->flutterwaveSubscriptionId}/cancel");

                if ($response->failed()) {
                    Log::error('Flutterwave cancellation failed', ['response' => $response->json()]);
                    return response()->json(['message' => 'Failed to cancel via Flutterwave'], 500);
                }

                $subscription->flutterwave_cancelled_at = $now;
            } catch (\Exception $e) {
                Log::error('Flutterwave API error', ['error' => $e->getMessage()]);
                // Continue with local deactivation even if API fails, or abort based on policy
            }
        }

        // Deactivate
        $subscription->status = 'cancelled'; // Or 'expired' based on your schema
        $subscription->endDate = $request->immediate ? $now : $subscription->nextBillingDate;
        $subscription->metadata = array_merge($subscription->metadata ?? [], [
            'manual_deactivation' => true,
            'deactivation_reason' => $request->reason,
            'deactivated_at' => $now->toDateTimeString(),
        ]);
        $subscription->save();

        // Optional: Downgrade user's plan to free/default
        $subscription->user->update(['currentPlan' => 1]); // Assuming 1 is free plan

        Log::info("Subscription {$subscription->subscriptionId} manually deactivated", ['user_id' => $subscription->userId]);

        return response()->json([
            'message' => 'Subscription deactivated successfully',
            'subscription' => $subscription->fresh(['user', 'plan.currency_detail']),
        ]);
    }

    // PATCH /subscriptions/{id}/expire
    public function expire(Request $request, $subscriptionId)
    {
        $subscription = Subscription::where('subscriptionId', $subscriptionId)->first();

        $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        if (!$subscription) {
            return response()->json(['error' => 'Subscription not found'], 404);
        }

        if ($subscription->status !== 'active') {
            return response()->json(['message' => 'Subscription is not active'], 400);
        }

        $now = Carbon::now();

        // Mark as expired locally without contacting Flutterwave (manual/admin expire)
        $subscription->status = 'expired';
        $subscription->endDate = $now;
        $subscription->metadata = array_merge($subscription->metadata ?? [], [
            'manual_expire' => true,
            'expire_reason' => $request->reason,
            'expired_at' => $now->toDateTimeString(),
        ]);
        $subscription->save();

        // Downgrade user plan to default/free
        try {
            $subscription->user->update(['currentPlan' => 1]);
        } catch (\Exception $e) {
            Log::warning('Failed to downgrade user after expire', ['subscription' => $subscriptionId, 'error' => $e->getMessage()]);
        }

        Log::info("Subscription {$subscription->subscriptionId} manually expired", ['user_id' => $subscription->userId]);

        return response()->json([
            'message' => 'Subscription expired successfully',
            'subscription' => $subscription->fresh(['user', 'plan.currency_detail']),
        ]);
    }
}