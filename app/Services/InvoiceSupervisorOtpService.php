<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class InvoiceSupervisorOtpService
{
    private const OTP_TTL_SECONDS = 600;

    private const TOKEN_TTL_SECONDS = 900;

    private const MAX_OTP_REQUESTS_PER_WINDOW = 5;

    private const RATE_WINDOW_SECONDS = 900;

    public function otpPendingKey(int|string $tenantId, int $actorUserId, string $invoiceId): string
    {
        return 'inv_sup_otp:'.(string) $tenantId.':'.$actorUserId.':'.sha1($invoiceId);
    }

    public function tokenKey(string $token): string
    {
        return 'inv_sup_tok:'.$token;
    }

    public function rateKey(int $actorUserId): string
    {
        return 'inv_sup_otp_rate:'.$actorUserId;
    }

    /**
     * Generate and email 6-digit OTP to tenant owner's email. Does not store OTP on User model.
     *
     * @return array{masked_email: string}
     */
    public function sendOwnerOtp(Tenant $tenant, Invoice $invoice, User $actor): array
    {
        $owner = User::find($tenant->ownerId);
        if (! $owner || ! filter_var($owner->email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Business owner has no valid email on file.');
        }

        $rateKey = $this->rateKey((int) $actor->id);
        $count = (int) Cache::get($rateKey, 0);
        if ($count >= self::MAX_OTP_REQUESTS_PER_WINDOW) {
            throw new \RuntimeException('Too many code requests. Try again later.');
        }
        Cache::put($rateKey, $count + 1, self::RATE_WINDOW_SECONDS);

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $pendingKey = $this->otpPendingKey($tenant->tenantId, (int) $actor->id, $invoice->invoiceId);
        Cache::put($pendingKey, password_hash($otp, PASSWORD_DEFAULT), self::OTP_TTL_SECONDS);

        $actorLabel = trim(implode(' ', array_filter([$actor->firstName, $actor->lastName]))) ?: $actor->email;
        $invoiceLabel = $invoice->userGeneratedInvoiceId ?: $invoice->invoiceId;

        try {
            Mail::send('emails.invoice-owner-supervisor-otp', [
                'otp' => $otp,
                'ownerName' => trim(implode(' ', array_filter([$owner->firstName, $owner->lastName]))) ?: 'there',
                'actorName' => $actorLabel,
                'actorEmail' => $actor->email,
                'tenantName' => $tenant->tenantName ?? 'Your business',
                'invoiceLabel' => $invoiceLabel,
                'expires_in' => '10 minutes',
            ], function ($message) use ($owner, $invoiceLabel) {
                $message->to($owner->email)
                    ->subject('Approval code: invoice change ('.$invoiceLabel.')');
            });
        } catch (\Throwable $e) {
            Cache::forget($pendingKey);
            Log::error('Invoice supervisor OTP mail failed: '.$e->getMessage());

            throw new \RuntimeException('Could not send approval code email.');
        }

        return [
            'masked_email' => $this->maskEmail($owner->email),
        ];
    }

    /**
     * Verify OTP and return a short-lived action token (send on void / amend requests).
     */
    public function verifyAndIssueToken(
        int|string $tenantId,
        int $actorUserId,
        string $invoiceId,
        string $otp
    ): string {
        $pendingKey = $this->otpPendingKey($tenantId, $actorUserId, $invoiceId);
        $hash = Cache::get($pendingKey);
        if (! $hash || ! password_verify($otp, $hash)) {
            throw new \RuntimeException('Invalid or expired code.');
        }

        Cache::forget($pendingKey);

        $token = bin2hex(random_bytes(32));
        $payload = [
            'tenantId' => (string) $tenantId,
            'userId' => $actorUserId,
            'invoiceId' => $invoiceId,
            'expires_at' => now()->addSeconds(self::TOKEN_TTL_SECONDS)->timestamp,
        ];
        Cache::put($this->tokenKey($token), $payload, self::TOKEN_TTL_SECONDS);

        return $token;
    }

    public function assertValidToken(
        string $token,
        int|string $tenantId,
        int $actorUserId,
        string $invoiceId
    ): void {
        $payload = Cache::get($this->tokenKey($token));
        if (! is_array($payload)) {
            throw new \RuntimeException('Invalid or expired approval. Request a new code.');
        }

        if ((string) $payload['tenantId'] !== (string) $tenantId
            || (int) $payload['userId'] !== $actorUserId
            || (string) $payload['invoiceId'] !== $invoiceId) {
            throw new \RuntimeException('This approval does not match this invoice.');
        }

        if (($payload['expires_at'] ?? 0) < now()->timestamp) {
            Cache::forget($this->tokenKey($token));
            throw new \RuntimeException('Approval has expired. Request a new code.');
        }
    }

    private function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '***';
        }
        [$local, $domain] = explode('@', $email, 2);
        $keep = max(1, min(2, strlen($local) - 1));
        $maskedLocal = substr($local, 0, $keep).str_repeat('*', max(2, strlen($local) - $keep));

        return $maskedLocal.'@'.$domain;
    }
}
