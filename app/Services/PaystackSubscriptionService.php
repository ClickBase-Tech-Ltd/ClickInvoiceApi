<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Subscription;
use App\Notifications\SubscriptionBillingNotice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaystackSubscriptionService
{
    public function verifyAndApply(string $reference, PaystackClient $paystack, array $eventData = []): array
    {
        $payment = Payment::with('subscription.plan.currency_detail', 'subscription.user')
            ->where('provider', 'paystack')
            ->where('providerReference', $reference)
            ->first();

        if (!$payment || !$payment->subscription || !$payment->subscription->plan) {
            return ['status' => 'not_found'];
        }

        if ($payment->status === 'successful' && $payment->subscription->status === 'active') {
            $eventSubscriptionCode = $eventData['subscription']['subscription_code'] ?? null;
            if (!$payment->subscription->providerSubscriptionId && $eventSubscriptionCode) {
                $eventNextPaymentDate = $eventData['subscription']['next_payment_date'] ?? null;
                $payment->subscription->update([
                    'providerSubscriptionId' => (string) $eventSubscriptionCode,
                    'providerSubscriptionEmailToken' => $eventData['subscription']['email_token']
                        ?? $payment->subscription->providerSubscriptionEmailToken,
                    'providerCustomerCode' => $eventData['customer']['customer_code']
                        ?? $payment->subscription->providerCustomerCode,
                    ...($eventNextPaymentDate && Carbon::parse($eventNextPaymentDate)->isFuture()
                        ? ['nextBillingDate' => Carbon::parse($eventNextPaymentDate)]
                        : []),
                ]);
            }

            return ['status' => 'successful'];
        }

        $response = $paystack->verifyTransaction($reference);
        if (!$response->successful() || $response->json('data.status') !== 'success') {
            return ['status' => 'pending'];
        }

        $transaction = $response->json('data', []);
        $plan = $payment->subscription->plan;
        $expectedAmount = (int) round(((float) $plan->price) * 100);
        $expectedCurrency = strtoupper((string) $plan->currency_detail?->currencyCode);

        if (
            ($transaction['reference'] ?? null) !== $reference ||
            (int) ($transaction['amount'] ?? -1) !== $expectedAmount ||
            strtoupper((string) ($transaction['currency'] ?? '')) !== $expectedCurrency ||
            strtolower((string) ($transaction['customer']['email'] ?? '')) !== strtolower((string) $payment->subscription->user?->email)
        ) {
            Log::warning('Paystack transaction did not match pending subscription payment', [
                'payment_id' => $payment->id,
                'reference' => $reference,
            ]);

            return ['status' => 'mismatch'];
        }

        $activated = DB::transaction(function () use ($payment, $reference, $transaction, $eventData) {
            $lockedPayment = Payment::query()->lockForUpdate()->find($payment->id);
            if (!$lockedPayment || $lockedPayment->status === 'successful') {
                return false;
            }

            $subscription = $lockedPayment->subscription()->lockForUpdate()->first();
            if (!$subscription || $subscription->status !== 'pending') {
                return false;
            }

            $now = Carbon::now();
            $subscriptionCode = $transaction['subscription']['subscription_code']
                ?? $transaction['subscription_code']
                ?? $eventData['subscription']['subscription_code']
                ?? null;
            $eventNextPaymentDate = $eventData['subscription']['next_payment_date']
                ?? $transaction['subscription']['next_payment_date']
                ?? null;
            $nextBillingDate = $eventNextPaymentDate && Carbon::parse($eventNextPaymentDate)->isFuture()
                ? Carbon::parse($eventNextPaymentDate)
                : $now->copy()->addMonthNoOverflow();

            $lockedPayment->update([
                'status' => 'successful',
                'providerTransactionId' => (string) ($transaction['id'] ?? ''),
                'responseData' => [
                    'transaction_id' => $transaction['id'] ?? null,
                    'reference' => $reference,
                    'status' => $transaction['status'] ?? null,
                    'amount' => $transaction['amount'] ?? null,
                    'currency' => $transaction['currency'] ?? null,
                    'paid_at' => $transaction['paid_at'] ?? null,
                    'customer_code' => $transaction['customer']['customer_code']
                        ?? $eventData['customer']['customer_code']
                        ?? null,
                    'subscription_code' => $subscriptionCode,
                ],
            ]);

            $subscription->update([
                'status' => 'active',
                'startDate' => $now,
                'nextBillingDate' => $nextBillingDate,
                'providerSubscriptionId' => $subscriptionCode,
                'providerSubscriptionEmailToken' => $eventData['subscription']['email_token'] ?? null,
                'providerCustomerCode' => $transaction['customer']['customer_code']
                    ?? $eventData['customer']['customer_code']
                    ?? null,
                'metadata' => [
                    'provider' => 'paystack',
                    'provider_reference' => $lockedPayment->providerReference,
                ],
            ]);

            $subscription->user?->update(['currentPlan' => $subscription->planId]);

            return true;
        });

        $payment->refresh();

        if ($activated) {
            $payment->load('subscription.plan.currency_detail', 'subscription.user');
            $this->sendBillingNotice(
                $payment->subscription,
                'payment_confirmed',
                (string) $payment->amount,
                (string) $payment->currency,
                $payment->subscription?->nextBillingDate
            );
        }

        return ['status' => $payment->status === 'successful' ? 'successful' : 'pending'];
    }

    public function verifyAndApplyRenewal(
        string $reference,
        ?string $eventSubscriptionCode,
        ?string $nextPaymentDate,
        ?string $invoiceCode,
        PaystackClient $paystack
    ): array {
        $existingPayment = Payment::query()
            ->where('provider', 'paystack')
            ->where('providerReference', $reference)
            ->first();

        if ($existingPayment) {
            return ['status' => $existingPayment->status === 'successful' ? 'successful' : 'pending'];
        }

        $response = $paystack->verifyTransaction($reference);
        if (!$response->successful() || $response->json('data.status') !== 'success') {
            return ['status' => 'pending'];
        }

        $transaction = $response->json('data', []);
        $verifiedReference = (string) ($transaction['reference'] ?? '');
        $verifiedSubscriptionCode = (string) (
            $transaction['subscription']['subscription_code']
            ?? $transaction['subscription_code']
            ?? ''
        );

        if ($verifiedReference !== $reference) {
            return ['status' => 'mismatch'];
        }

        if (
            $eventSubscriptionCode &&
            $verifiedSubscriptionCode &&
            !hash_equals($verifiedSubscriptionCode, $eventSubscriptionCode)
        ) {
            return ['status' => 'mismatch'];
        }

        $subscriptionCode = $verifiedSubscriptionCode ?: $eventSubscriptionCode;
        if (!$subscriptionCode) {
            return ['status' => 'not_found'];
        }

        $subscription = \App\Models\Subscription::with('plan.currency_detail', 'user')
            ->where('provider', 'paystack')
            ->where('providerSubscriptionId', $subscriptionCode)
            ->first();

        if (!$subscription || !$subscription->plan || !$subscription->user) {
            return ['status' => 'not_found'];
        }

        $firstPayment = Payment::query()
            ->where('subscriptionId', $subscription->subscriptionId)
            ->where('provider', 'paystack')
            ->where('status', 'successful')
            ->orderBy('id')
            ->first();
        $expectedAmount = (int) round(((float) ($firstPayment?->amount ?? $subscription->plan->price)) * 100);
        $expectedCurrency = strtoupper((string) (
            $firstPayment?->currency ?? $subscription->plan->currency_detail?->currencyCode
        ));

        if (
            (int) ($transaction['amount'] ?? -1) !== $expectedAmount ||
            strtoupper((string) ($transaction['currency'] ?? '')) !== $expectedCurrency ||
            strtolower((string) ($transaction['customer']['email'] ?? '')) !== strtolower((string) $subscription->user->email)
        ) {
            Log::warning('Paystack renewal transaction did not match subscription', [
                'subscription_id' => $subscription->subscriptionId,
                'reference' => $reference,
            ]);

            return ['status' => 'mismatch'];
        }

        $paidAt = Carbon::parse($transaction['paid_at'] ?? now());
        $nextBillingDate = $nextPaymentDate
            ? Carbon::parse($nextPaymentDate)
            : ($subscription->nextBillingDate && $subscription->nextBillingDate->gt($paidAt)
                ? $subscription->nextBillingDate->copy()
                : $paidAt->copy())->addMonthNoOverflow();

        if ($nextBillingDate->lte($paidAt)) {
            return ['status' => 'mismatch'];
        }

        $applicationResult = DB::transaction(function () use (
            $subscription,
            $reference,
            $subscriptionCode,
            $transaction,
            $expectedCurrency,
            $nextBillingDate,
            $invoiceCode
        ) {
            $lockedSubscription = \App\Models\Subscription::query()
                ->lockForUpdate()
                ->find($subscription->subscriptionId);

            if (!$lockedSubscription) {
                return 'not_found';
            }

            $duplicate = Payment::query()
                ->where('provider', 'paystack')
                ->where('providerReference', $reference)
                ->lockForUpdate()
                ->first();

            if ($duplicate) {
                return $duplicate->subscriptionId === $lockedSubscription->subscriptionId
                    && $duplicate->status === 'successful'
                    ? 'duplicate'
                    : 'mismatch';
            }

            Payment::create([
                'subscriptionId' => $lockedSubscription->subscriptionId,
                'provider' => 'paystack',
                'providerReference' => $reference,
                'providerTransactionId' => isset($transaction['id']) ? (string) $transaction['id'] : null,
                'userId' => $lockedSubscription->userId,
                'amount' => ((int) $transaction['amount']) / 100,
                'currency' => $expectedCurrency,
                'status' => 'successful',
                'responseData' => [
                    'reference' => $reference,
                    'transaction_id' => $transaction['id'] ?? null,
                    'subscription_code' => $subscriptionCode,
                    'invoice_code' => $invoiceCode,
                    'paid_at' => $transaction['paid_at'] ?? null,
                ],
            ]);

            $metadata = is_array($lockedSubscription->metadata) ? $lockedSubscription->metadata : [];
            $lockedSubscription->update([
                'status' => 'active',
                'endDate' => null,
                'nextBillingDate' => $nextBillingDate,
                'metadata' => array_merge($metadata, [
                    'last_renewal_reference' => $reference,
                    'last_renewal_invoice' => $invoiceCode,
                    'last_renewal_at' => now()->toIso8601String(),
                ]),
            ]);

            $lockedSubscription->user?->update(['currentPlan' => $lockedSubscription->planId]);

            return 'applied';
        });

        if ($applicationResult === 'applied') {
            $this->sendBillingNotice(
                $subscription->fresh(['plan.currency_detail', 'user']),
                'renewal_success',
                number_format(((int) $transaction['amount']) / 100, 2, '.', ''),
                $expectedCurrency,
                $nextBillingDate
            );
        }

        return [
            'status' => in_array($applicationResult, ['applied', 'duplicate'], true)
                ? 'successful'
                : $applicationResult,
        ];
    }

    public function recordRenewalFailure(string $subscriptionCode, ?string $invoiceCode): bool
    {
        $subscription = DB::transaction(function () use ($subscriptionCode, $invoiceCode) {
            $subscription = Subscription::query()
                ->where('provider', 'paystack')
                ->where('providerSubscriptionId', $subscriptionCode)
                ->lockForUpdate()
                ->first();

            if (!$subscription) {
                return null;
            }

            $metadata = is_array($subscription->metadata) ? $subscription->metadata : [];
            if (
                $subscription->status === 'past_due' ||
                ($metadata['last_renewal_failure']['invoice_code'] ?? null) === $invoiceCode
            ) {
                return null;
            }

            $graceEndsAt = Carbon::now()->addDays((int) config('services.paystack.renewal_grace_days', 3));
            $metadata['last_renewal_failure'] = [
                'invoice_code' => $invoiceCode,
                'failed_at' => Carbon::now()->toIso8601String(),
                'grace_ends_at' => $graceEndsAt->toIso8601String(),
            ];
            $subscription->update([
                'status' => 'past_due',
                'endDate' => $graceEndsAt,
                'metadata' => $metadata,
            ]);

            return $subscription->fresh(['plan.currency_detail', 'user']);
        });

        if (!$subscription) {
            return false;
        }

        $graceEndsAt = Carbon::parse($subscription->metadata['last_renewal_failure']['grace_ends_at']);
        $this->sendBillingNotice(
            $subscription,
            'renewal_failed',
            number_format((float) $subscription->plan?->price, 2, '.', ''),
            (string) $subscription->plan?->currency_detail?->currencyCode,
            $graceEndsAt
        );

        return true;
    }

    private function sendBillingNotice(
        ?Subscription $subscription,
        string $type,
        string $amount,
        string $currency,
        ?Carbon $effectiveDate
    ): void {
        if (!$subscription?->user || !$effectiveDate) {
            return;
        }

        try {
            $subscription->user->notify(new SubscriptionBillingNotice(
                $type,
                (string) ($subscription->plan?->planName ?? 'subscription'),
                $amount,
                $currency,
                $effectiveDate->toFormattedDateString()
            ));
        } catch (\Throwable $exception) {
            Log::warning('Subscription billing notice could not be queued', [
                'subscription_id' => $subscription->subscriptionId,
                'notice_type' => $type,
            ]);
        }
    }
}