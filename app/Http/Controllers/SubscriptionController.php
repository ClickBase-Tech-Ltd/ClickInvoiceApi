<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Plans;
use App\Models\Subscription;
use App\Models\User; // Assuming auth

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
        $user = auth()->user(); // Or however you get authenticated user
        $plan = Plans::with('currency_detail')->where('planId', $planId)->first();

        if (!$plan->flutterwavePlanId) {
            return response()->json(['error' => 'Invalid plan'], 400);
        }

        // Check if user already has active subscription
        $existingSub = $user->subscription;
        if ($existingSub && $existingSub->status === 'active' && $existingSub->planId == $plan->planId) {
            return response()->json(['error' => 'You already have an active subscription on this plan'], 400);
        }

        // Create pending subscription record
        $subscription = Subscription::create([
            'userId' => $user->id,
            'planId' => $plan->planId,
            'flutterwaveSubscriptionId' => $plan->flutterwaveSubscriptionId,
            'status' => 'pending',
        ]);

        $txRef = 'sub-' . $subscription->subscriptionId . '-' . time();

      
        // Initiate payment on Flutterwave
        // Hardcode for testing; remove in production
$ngrokUrl = 'https://otiosely-chronological-cari.ngrok-free.dev'; // Your ngrok URL
// 'redirect_url' => $ngrokUrl . '/subscription/redirect',
        $secretKey = env('FLUTTERWAVE_SECRET_KEY');
        $response = Http::withHeaders(['Authorization' => "Bearer $secretKey"])
            ->post('https://api.flutterwave.com/v3/payments', [
                'tx_ref' => $txRef,
                'amount' => $plan->price, // Overridden by plan, but include for initial charge
                'currency' => $plan->currency_detail->currencyCode,
                'interval' => 'monthly',
                'payment_plan' => $plan->flutterwavePlanId, // Enables subscription
                // 'redirect_url' => url('/subscription/redirect'), // Your frontend or backend redirect handler
                'redirect_url' => env('APP_URL') . '/api/subscription/verify-redirect',
                'customer' => [
                    'email' => $user->email,
                    'name' => $user->name,
                ],
                'customizations' => [
                    'title' => 'ClickInvoice ' . $plan->planName . ' Subscription',
                    'description' => 'Subscribe to ' . $plan->planName,
                ],
                'meta' => [
                    'subscriptionId' => $subscription->subscriptionId, // For webhook
                ],
            ]);

        if ($response->successful()) {
              $payment = Payment::create([
            'subscriptionId' => $subscription->subscriptionId,
            'amount' => $plan->price,
            'currency' => $plan->currency_detail->currencyCode,
            'status' => 'pending',
            'flutterwaveTxRef' => $txRef,
            'flutterwaveTxId' =>  $response->json()['data']['id'],
            'userId' => $user->id,
        ]);
            $link = $response->json()['data']['link'];
            return response()->json(['payment_link' => $link]);
        } else {
            $subscription->delete(); // Cleanup
            return response()->json(['error' => 'Failed to initiate payment'], 500);
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
}