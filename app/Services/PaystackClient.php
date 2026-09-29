<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use LogicException;

class PaystackClient
{
    private const API_URL = 'https://api.paystack.co';

    public function createPlan(string $name, int $amount, string $currency, string $interval): Response
    {
        return $this->request()->post(self::API_URL . '/plan', [
            'name' => $name,
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'interval' => $interval,
        ]);
    }

    public function initializeSubscription(
        string $email,
        int $amount,
        string $currency,
        string $planCode,
        string $reference,
        string $callbackUrl,
        array $metadata
    ): Response {
        return $this->request()->post(self::API_URL . '/transaction/initialize', [
            'email' => $email,
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'plan' => $planCode,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ]);
    }

    public function verifyTransaction(string $reference): Response
    {
        return $this->request()->get(self::API_URL . '/transaction/verify/' . rawurlencode($reference));
    }

    public function getSubscriptionManagementLink(string $subscriptionCode): Response
    {
        return $this->request()->get(
            self::API_URL . '/subscription/' . rawurlencode($subscriptionCode) . '/manage/link'
        );
    }

    public function disableSubscription(string $subscriptionCode, string $emailToken): Response
    {
        return $this->request()->post(self::API_URL . '/subscription/disable', [
            'code' => $subscriptionCode,
            'token' => $emailToken,
        ]);
    }

    public function hasValidSignature(string $payload, ?string $signature): bool
    {
        $secret = config('services.paystack.secret_key');

        if (!$secret || !$signature || !$this->isConfiguredForCurrentEnvironment()) {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $payload, $secret), $signature);
    }

    public function isConfiguredForCurrentEnvironment(): bool
    {
        $secret = (string) config('services.paystack.secret_key');

        return app()->environment('production')
            ? str_starts_with($secret, 'sk_live_')
            : str_starts_with($secret, 'sk_test_');
    }

    private function request()
    {
        if (!$this->isConfiguredForCurrentEnvironment()) {
            throw new LogicException('Paystack credentials do not match the application environment.');
        }

        return Http::withToken(config('services.paystack.secret_key'))
            ->acceptJson()
            ->timeout(15);
    }
}