<?php

namespace App\Http\Controllers;

use App\Services\PaystackClient;
use App\Services\PaystackSubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaystackWebhookController extends Controller
{
    public function handle(
        Request $request,
        PaystackClient $paystack,
        PaystackSubscriptionService $subscriptions
    ) {
        if (!$paystack->hasValidSignature($request->getContent(), $request->header('x-paystack-signature'))) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $event = $request->input('event');
        if ($event === 'charge.success') {
            $reference = $request->input('data.reference');
            if (!$reference) {
                return response()->json(['message' => 'Payment reference missing'], 400);
            }

            $result = $subscriptions->verifyAndApply((string) $reference, $paystack, $request->input('data', []));
            if ($result['status'] === 'mismatch') {
                Log::warning('Paystack webhook transaction mismatch', ['reference' => $reference]);
            }
        } elseif ($event === 'invoice.update') {
            $invoice = $request->input('data', []);
            if (($invoice['status'] ?? null) !== 'success' || !($invoice['paid'] ?? false)) {
                return response()->json(['status' => 'ignored']);
            }

            $reference = $invoice['transaction']['reference'] ?? null;
            if (!$reference) {
                return response()->json(['message' => 'Invoice transaction reference missing'], 400);
            }

            $result = $subscriptions->verifyAndApply((string) $reference, $paystack, $invoice);
            if ($result['status'] === 'not_found') {
                $result = $subscriptions->verifyAndApplyRenewal(
                    (string) $reference,
                    $invoice['subscription']['subscription_code'] ?? null,
                    $invoice['subscription']['next_payment_date'] ?? null,
                    $invoice['invoice_code'] ?? null,
                    $paystack
                );
            }

            if ($result['status'] === 'pending') {
                return response()->json(['message' => 'Payment verification is pending'], 503);
            }

            if (in_array($result['status'], ['mismatch', 'not_found'], true)) {
                Log::warning('Paystack subscription invoice was not applied', [
                    'reference' => $reference,
                    'status' => $result['status'],
                ]);
            }
        } elseif ($event === 'invoice.payment_failed') {
            $invoice = $request->input('data', []);
            $subscriptionCode = $invoice['subscription']['subscription_code'] ?? null;

            if ($subscriptionCode) {
                $subscriptions->recordRenewalFailure(
                    (string) $subscriptionCode,
                    isset($invoice['invoice_code']) ? (string) $invoice['invoice_code'] : null
                );
            }
        }

        return response()->json(['status' => 'success']);
    }
}