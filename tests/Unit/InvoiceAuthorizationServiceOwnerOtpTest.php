<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceAuthorizationService;
use Tests\TestCase;

class InvoiceAuthorizationServiceOwnerOtpTest extends TestCase
{
    private InvoiceAuthorizationService $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = new InvoiceAuthorizationService;
    }

    public function test_requires_business_owner_otp_when_tenant_has_owner_regardless_of_actor(): void
    {
        $tenant = new Tenant(['ownerId' => 100]);
        $nonOwner = new User;
        $nonOwner->id = 50;
        $ownerUser = new User;
        $ownerUser->id = 100;

        $this->assertTrue($this->auth->requiresBusinessOwnerOtp($nonOwner, $tenant));
        $this->assertTrue($this->auth->requiresBusinessOwnerOtp($ownerUser, $tenant));
    }

    public function test_requires_business_owner_otp_false_when_tenant_has_no_owner(): void
    {
        $tenant = new Tenant(['ownerId' => null]);
        $user = new User;
        $user->id = 50;

        $this->assertFalse($this->auth->requiresBusinessOwnerOtp($user, $tenant));
        $this->assertFalse($this->auth->tenantHasOwnerForSupervisoryOtp($tenant));
    }

    public function test_tenant_has_owner_for_supervisory_otp(): void
    {
        $with = new Tenant(['ownerId' => 1]);
        $without = new Tenant(['ownerId' => null]);

        $this->assertTrue($this->auth->tenantHasOwnerForSupervisoryOtp($with));
        $this->assertFalse($this->auth->tenantHasOwnerForSupervisoryOtp($without));
    }

    public function test_is_tenant_owner(): void
    {
        $tenant = new Tenant(['ownerId' => 7]);
        $owner = new User;
        $owner->id = 7;
        $other = new User;
        $other->id = 8;

        $this->assertTrue($this->auth->isTenantOwner($owner, $tenant));
        $this->assertFalse($this->auth->isTenantOwner($other, $tenant));
    }
}
