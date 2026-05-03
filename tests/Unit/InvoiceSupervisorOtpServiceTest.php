<?php

namespace Tests\Unit;

use App\Services\InvoiceSupervisorOtpService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class InvoiceSupervisorOtpServiceTest extends TestCase
{
    private InvoiceSupervisorOtpService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InvoiceSupervisorOtpService;
        Cache::flush();
    }

    public function test_verify_and_issue_token_succeeds_when_pending_otp_matches(): void
    {
        $tenantId = 10;
        $actorId = 20;
        $invoiceId = 'INV-UNIT-1';
        $otp = '424242';

        $key = $this->service->otpPendingKey($tenantId, $actorId, $invoiceId);
        Cache::put($key, password_hash($otp, PASSWORD_DEFAULT), 600);

        $token = $this->service->verifyAndIssueToken($tenantId, $actorId, $invoiceId, $otp);

        $this->assertSame(64, strlen($token));
        $this->assertNull(Cache::get($key));
        $this->service->assertValidToken($token, $tenantId, $actorId, $invoiceId);
    }

    public function test_verify_rejects_invalid_otp(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid or expired code.');

        $tenantId = 10;
        $actorId = 20;
        $invoiceId = 'INV-UNIT-2';

        Cache::put(
            $this->service->otpPendingKey($tenantId, $actorId, $invoiceId),
            password_hash('111111', PASSWORD_DEFAULT),
            600
        );

        $this->service->verifyAndIssueToken($tenantId, $actorId, $invoiceId, '999999');
    }

    public function test_assert_valid_token_rejects_wrong_invoice(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This approval does not match this invoice.');

        $tenantId = 1;
        $actorId = 2;
        $invoiceId = 'INV-A';
        $otp = '123456';

        Cache::put(
            $this->service->otpPendingKey($tenantId, $actorId, $invoiceId),
            password_hash($otp, PASSWORD_DEFAULT),
            600
        );

        $token = $this->service->verifyAndIssueToken($tenantId, $actorId, $invoiceId, $otp);
        $this->service->assertValidToken($token, $tenantId, $actorId, 'INV-B');
    }

    public function test_assert_valid_token_rejects_unknown_token(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid or expired approval.');

        $this->service->assertValidToken(str_repeat('a', 64), 1, 1, 'INV-X');
    }

    public function test_assert_valid_token_rejects_expired_payload(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Approval has expired.');

        $token = bin2hex(random_bytes(32));
        Cache::put($this->service->tokenKey($token), [
            'tenantId' => '1',
            'userId' => 2,
            'invoiceId' => 'INV-Z',
            'expires_at' => now()->subMinute()->timestamp,
        ], 900);

        $this->service->assertValidToken($token, '1', 2, 'INV-Z');
    }
}
