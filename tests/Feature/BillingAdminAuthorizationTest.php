<?php

namespace Tests\Feature;

use App\Http\Controllers\PlansController;
use App\Http\Controllers\SubscriptionController;
use App\Models\Currency;
use App\Models\Plans;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PaystackClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillingAdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['roleName' => 'USER']);
        $this->user = User::create([
            'firstName' => 'Regular',
            'lastName' => 'Customer',
            'email' => 'regular@example.test',
            'phoneNumber' => '+10000000000',
            'password' => 'test-password',
            'role' => $role->roleId,
        ]);

        Auth::login($this->user);
        Http::fake();
    }

    public function test_regular_user_cannot_manage_billing_plans_or_subscriptions(): void
    {
        $plans = app(PlansController::class);
        $subscriptions = app(SubscriptionController::class);

        $this->assertSame(403, $plans->store(Request::create('/subscription-plans', 'POST'), app(PaystackClient::class))->getStatusCode());
        $this->assertSame(403, $plans->update(Request::create('/subscription-plans/1', 'PATCH'), 1, app(PaystackClient::class))->getStatusCode());
        $this->assertSame(403, $subscriptions->index(Request::create('/subscribers', 'GET'))->getStatusCode());
        $this->assertSame(403, $subscriptions->activate(Request::create('/activate', 'PATCH'), 1)->getStatusCode());
        $this->assertSame(403, $subscriptions->deactivate(Request::create('/deactivate', 'PATCH'), 1)->getStatusCode());
        $this->assertSame(403, $subscriptions->expire(Request::create('/expire', 'PATCH'), 1)->getStatusCode());
        $this->assertSame(403, $subscriptions->assignManual(Request::create('/assign', 'POST'))->getStatusCode());
        $this->assertSame(403, $subscriptions->bulkAction(Request::create('/bulk-action', 'PATCH'))->getStatusCode());

        Http::assertNothingSent();
    }

    public function test_customer_cancellation_does_not_claim_success_or_mutate_subscription(): void
    {
        $currency = Currency::create([
            'currencyName' => 'Test Naira',
            'currencyCode' => 'NGN',
            'currencySymbol' => 'N',
            'country' => 'Testland',
        ]);
        $plan = Plans::create([
            'planName' => 'Paid',
            'price' => 100,
            'currency' => $currency->currencyId,
        ]);
        $subscription = Subscription::create([
            'userId' => $this->user->id,
            'planId' => $plan->planId,
            'provider' => 'paystack',
            'status' => 'active',
        ]);

        $response = app(SubscriptionController::class)->cancel(Request::create('/subscriptions/cancel', 'PUT'));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertNull($subscription->fresh()->endDate);
        Http::assertNothingSent();
    }
}
