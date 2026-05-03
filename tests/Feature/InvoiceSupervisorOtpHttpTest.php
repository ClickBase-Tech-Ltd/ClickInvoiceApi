<?php

namespace Tests\Feature;

use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\VerifyJwtToken;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantStaff;
use App\Models\User;
use App\Services\InvoiceSupervisorOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InvoiceSupervisorOtpHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::flush();
    }

    /**
     * @return object{owner: User, manager: User, tenant: Tenant, invoice: Invoice}
     */
    private function seedTenantWithInvoice(): object
    {
        $currency = Currency::create([
            'currencyName' => 'Test',
            'currencyCode' => 'TST',
            'currencySymbol' => 'T',
            'country' => 'Testland',
        ]);
        $role = Role::create(['roleName' => 'USER']);

        $owner = User::create([
            'firstName' => 'Owner',
            'lastName' => 'One',
            'email' => 'owner@example.test',
            'phoneNumber' => '000',
            'password' => bcrypt('secret'),
            'role' => $role->roleId,
        ]);

        $manager = User::create([
            'firstName' => 'Manager',
            'lastName' => 'Two',
            'email' => 'manager@example.test',
            'phoneNumber' => '001',
            'password' => bcrypt('secret'),
            'role' => $role->roleId,
        ]);

        $tenant = Tenant::create([
            'tenantName' => 'Shop',
            'ownerId' => $owner->id,
            'status' => 'active',
            'currency' => $currency->currencyId,
        ]);

        TenantStaff::create([
            'tenantId' => $tenant->tenantId,
            'userId' => $manager->id,
            'role' => 'manager',
        ]);

        $invoice = Invoice::create([
            'invoiceId' => 'INV-HTTP-OTP-1',
            'tenantId' => $tenant->tenantId,
            'createdBy' => $owner->id,
            'currency' => $currency->currencyId,
            'status' => 'UNPAID',
            'balanceDue' => '100',
        ]);

        return (object) [
            'owner' => $owner,
            'manager' => $manager,
            'tenant' => $tenant,
            'invoice' => $invoice,
        ];
    }

    public function test_supervisor_otp_request_sends_for_tenant_owner_too(): void
    {
        $s = $this->seedTenantWithInvoice();

        $this->withoutMiddleware([VerifyJwtToken::class, IdentifyTenant::class]);
        $this->actingAs($s->owner, 'web');

        $res = $this->postJson("/api/invoices/{$s->invoice->invoiceId}/supervisor-otp/request", [], [
            'X-Tenant-ID' => (string) $s->tenant->tenantId,
        ]);

        $res->assertOk()
            ->assertJsonStructure(['message', 'masked_email']);
        $this->assertArrayNotHasKey('skipped', $res->json());
    }

    public function test_supervisor_otp_request_ok_for_manager(): void
    {
        $s = $this->seedTenantWithInvoice();

        $this->withoutMiddleware([VerifyJwtToken::class, IdentifyTenant::class]);
        $this->actingAs($s->manager, 'web');

        $res = $this->postJson("/api/invoices/{$s->invoice->invoiceId}/supervisor-otp/request", [], [
            'X-Tenant-ID' => (string) $s->tenant->tenantId,
        ]);

        $res->assertOk()
            ->assertJsonStructure(['message', 'masked_email']);
        $this->assertArrayNotHasKey('skipped', $res->json());
    }

    public function test_supervisor_otp_verify_rejects_invalid_code(): void
    {
        $s = $this->seedTenantWithInvoice();
        $otp = app(InvoiceSupervisorOtpService::class);
        $pendingKey = $otp->otpPendingKey($s->tenant->tenantId, $s->manager->id, $s->invoice->invoiceId);
        Cache::put($pendingKey, password_hash('111111', PASSWORD_DEFAULT), 600);

        $this->withoutMiddleware([VerifyJwtToken::class, IdentifyTenant::class]);
        $this->actingAs($s->manager, 'web');

        $res = $this->postJson("/api/invoices/{$s->invoice->invoiceId}/supervisor-otp/verify", [
            'otp' => '999999',
        ], [
            'X-Tenant-ID' => (string) $s->tenant->tenantId,
        ]);

        $res->assertStatus(422);
    }

    public function test_supervisor_otp_verify_returns_token(): void
    {
        $s = $this->seedTenantWithInvoice();
        $otpSvc = app(InvoiceSupervisorOtpService::class);
        $pendingKey = $otpSvc->otpPendingKey($s->tenant->tenantId, $s->manager->id, $s->invoice->invoiceId);
        Cache::put($pendingKey, password_hash('555555', PASSWORD_DEFAULT), 600);

        $this->withoutMiddleware([VerifyJwtToken::class, IdentifyTenant::class]);
        $this->actingAs($s->manager, 'web');

        $res = $this->postJson("/api/invoices/{$s->invoice->invoiceId}/supervisor-otp/verify", [
            'otp' => '555555',
        ], [
            'X-Tenant-ID' => (string) $s->tenant->tenantId,
        ]);

        $res->assertOk()
            ->assertJsonStructure(['supervisor_action_token', 'message']);
        $this->assertSame(64, strlen((string) $res->json('supervisor_action_token')));
    }

    public function test_void_requires_supervisor_token_for_manager(): void
    {
        $s = $this->seedTenantWithInvoice();

        $this->withoutMiddleware([VerifyJwtToken::class, IdentifyTenant::class]);
        $this->actingAs($s->manager, 'web');

        $res = $this->postJson("/api/invoices/{$s->invoice->invoiceId}/void", [
            'reason' => 'Test void',
        ], [
            'X-Tenant-ID' => (string) $s->tenant->tenantId,
        ]);

        $res->assertStatus(422);
    }

    public function test_void_succeeds_for_manager_with_valid_supervisor_token(): void
    {
        $s = $this->seedTenantWithInvoice();
        $otpSvc = app(InvoiceSupervisorOtpService::class);
        $pendingKey = $otpSvc->otpPendingKey($s->tenant->tenantId, $s->manager->id, $s->invoice->invoiceId);
        Cache::put($pendingKey, password_hash('888888', PASSWORD_DEFAULT), 600);

        $this->withoutMiddleware([VerifyJwtToken::class, IdentifyTenant::class]);
        $this->actingAs($s->manager, 'web');

        $verify = $this->postJson("/api/invoices/{$s->invoice->invoiceId}/supervisor-otp/verify", [
            'otp' => '888888',
        ], [
            'X-Tenant-ID' => (string) $s->tenant->tenantId,
        ]);
        $verify->assertOk();
        $token = $verify->json('supervisor_action_token');

        $void = $this->postJson("/api/invoices/{$s->invoice->invoiceId}/void", [
            'reason' => 'Corrected in test',
            'supervisor_action_token' => $token,
        ], [
            'X-Tenant-ID' => (string) $s->tenant->tenantId,
        ]);

        $void->assertOk()->assertJsonPath('invoice.status', 'VOID');
    }

    public function test_void_requires_supervisor_token_for_tenant_owner(): void
    {
        $s = $this->seedTenantWithInvoice();

        $this->withoutMiddleware([VerifyJwtToken::class, IdentifyTenant::class]);
        $this->actingAs($s->owner, 'web');

        $res = $this->postJson("/api/invoices/{$s->invoice->invoiceId}/void", [
            'reason' => 'Owner void',
        ], [
            'X-Tenant-ID' => (string) $s->tenant->tenantId,
        ]);

        $res->assertStatus(422);
    }
}
