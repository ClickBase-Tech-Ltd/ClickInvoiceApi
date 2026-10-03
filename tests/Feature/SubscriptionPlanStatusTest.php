<?php

namespace Tests\Feature;

use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\VerifyJwtToken;
use App\Models\Currency;
use App\Models\Plans;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPlanStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_catalog_distinguishes_expired_and_current_subscriptions(): void
    {
        $this->withoutMiddleware([VerifyJwtToken::class, IdentifyTenant::class]);

        $role = Role::create(['roleName' => 'USER']);
        $currency = Currency::create([
            'currencyName' => 'Nigerian Naira',
            'currencyCode' => 'NGN',
            'currencySymbol' => 'N',
            'country' => 'Nigeria',
        ]);
        $freePlan = Plans::create([
            'planName' => 'Starter',
            'price' => 0,
            'currency' => $currency->currencyId,
        ]);
        $expiredPlan = Plans::create([
            'planName' => 'Premium',
            'price' => 13300,
            'currency' => $currency->currencyId,
        ]);
        $currentPlan = Plans::create([
            'planName' => 'Basic',
            'price' => 6600,
            'currency' => $currency->currencyId,
        ]);
        $user = User::create([
            'firstName' => 'Plan',
            'lastName' => 'Customer',
            'email' => 'plan-customer@example.test',
            'phoneNumber' => '+10000000000',
            'password' => 'test-password',
            'role' => $role->roleId,
            'currentPlan' => $currentPlan->planId,
        ]);

        Subscription::create([
            'userId' => $user->id,
            'planId' => $expiredPlan->planId,
            'provider' => 'legacy',
            'status' => 'active',
            'startDate' => now()->subMonths(4),
            'nextBillingDate' => now()->subMonths(3),
            'endDate' => now()->subMonths(4),
        ]);
        Subscription::create([
            'userId' => $user->id,
            'planId' => $currentPlan->planId,
            'provider' => 'paystack',
            'status' => 'active',
            'startDate' => now()->subDays(5),
            'nextBillingDate' => now()->addDays(25),
        ]);

        $response = $this->actingAs($user, 'web')->getJson('/api/plans');
        $response->assertOk();
        $plans = collect($response->json())->keyBy('planId');

        $this->assertFalse($plans[$freePlan->planId]['is_subscribed']);
        $this->assertSame('expired', $plans[$expiredPlan->planId]['subscription_status']);
        $this->assertFalse($plans[$expiredPlan->planId]['is_subscribed']);
        $this->assertSame('active', $plans[$currentPlan->planId]['subscription_status']);
        $this->assertTrue($plans[$currentPlan->planId]['is_subscribed']);
    }

    public function test_past_due_subscription_remains_current_only_until_grace_deadline(): void
    {
        $this->withoutMiddleware([VerifyJwtToken::class, IdentifyTenant::class]);
        $role = Role::create(['roleName' => 'USER']);
        $currency = Currency::create([
            'currencyName' => 'Nigerian Naira',
            'currencyCode' => 'NGN',
            'currencySymbol' => 'N',
            'country' => 'Nigeria',
        ]);
        $freePlan = Plans::create([
            'planName' => 'Starter',
            'price' => 0,
            'currency' => $currency->currencyId,
        ]);
        $paidPlan = Plans::create([
            'planName' => 'Basic',
            'price' => 6600,
            'currency' => $currency->currencyId,
        ]);
        $user = User::create([
            'firstName' => 'Grace',
            'lastName' => 'Customer',
            'email' => 'grace-customer@example.test',
            'phoneNumber' => '+10000000001',
            'password' => 'test-password',
            'role' => $role->roleId,
            'currentPlan' => $paidPlan->planId,
        ]);
        Subscription::create([
            'userId' => $user->id,
            'planId' => $paidPlan->planId,
            'provider' => 'paystack',
            'providerSubscriptionId' => 'SUB_grace_test',
            'status' => 'past_due',
            'startDate' => now()->subMonth(),
            'nextBillingDate' => now()->subDay(),
            'endDate' => now()->addDays(2),
        ]);

        $response = $this->actingAs($user, 'web')->getJson('/api/plans');
        $response->assertOk();
        $plans = collect($response->json())->keyBy('planId');

        $this->assertSame('past_due', $plans[$paidPlan->planId]['subscription_status']);
        $this->assertTrue($plans[$paidPlan->planId]['is_subscribed']);
        $this->assertFalse($plans[$freePlan->planId]['is_subscribed']);
    }

    public function test_plan_catalog_keeps_subscription_current_through_admin_access_extension(): void
    {
        $this->withoutMiddleware([VerifyJwtToken::class, IdentifyTenant::class]);
        $role = Role::create(['roleName' => 'USER']);
        $currency = Currency::create([
            'currencyName' => 'Nigerian Naira',
            'currencyCode' => 'NGN',
            'currencySymbol' => 'N',
            'country' => 'Nigeria',
        ]);
        $paidPlan = Plans::create([
            'planName' => 'Basic',
            'price' => 6600,
            'currency' => $currency->currencyId,
        ]);
        $user = User::create([
            'firstName' => 'Extended',
            'lastName' => 'Customer',
            'email' => 'extended-customer@example.test',
            'phoneNumber' => '+10000000002',
            'password' => 'test-password',
            'role' => $role->roleId,
            'currentPlan' => $paidPlan->planId,
        ]);
        $accessThrough = now()->addMonth()->startOfSecond();
        Subscription::create([
            'userId' => $user->id,
            'planId' => $paidPlan->planId,
            'provider' => 'paystack',
            'providerSubscriptionId' => 'SUB_extended_plan_status',
            'status' => 'active',
            'startDate' => now()->subMonth(),
            'nextBillingDate' => now()->subDay(),
            'endDate' => now()->subDay(),
            'metadata' => ['admin_access_extension_until' => $accessThrough->toDateTimeString()],
        ]);

        $response = $this->actingAs($user, 'web')->getJson('/api/plans');
        $response->assertOk();
        $paidPlanStatus = collect($response->json())->keyBy('planId')->get($paidPlan->planId);

        $this->assertSame('active', $paidPlanStatus['subscription_status']);
        $this->assertTrue($paidPlanStatus['is_subscribed']);
        $this->assertSame($accessThrough->toIso8601String(), Carbon::parse($paidPlanStatus['subscription_ends_at'])->toIso8601String());
    }
}
