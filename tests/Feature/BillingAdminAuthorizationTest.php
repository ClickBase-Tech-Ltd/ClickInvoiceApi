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
use Illuminate\Support\Facades\Mail;
use App\Mail\UserNotificationMail;
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

    public function test_only_super_admin_can_award_a_complimentary_subscription_and_recipient_gets_branded_email(): void
    {
        $currency = Currency::create([
            'currencyName' => 'Test Naira',
            'currencyCode' => 'NGN',
            'currencySymbol' => 'N',
            'country' => 'Testland',
        ]);
        $plan = Plans::create([
            'planName' => 'Professional',
            'price' => 2500,
            'currency' => $currency->currencyId,
        ]);
        $recipient = User::create([
            'firstName' => 'Awarded',
            'lastName' => 'Customer',
            'email' => 'awarded@example.test',
            'phoneNumber' => '+10000000001',
            'password' => 'test-password',
            'role' => Role::create(['roleName' => 'USER'])->roleId,
        ]);

        $adminRole = Role::create(['roleName' => 'ADMIN']);
        $this->user->update(['role' => $adminRole->roleId]);
        Auth::setUser($this->user->fresh());
        $adminResponse = app(SubscriptionController::class)->assignManual(Request::create('/assign', 'POST', [
            'userId' => $recipient->id,
            'planId' => $plan->planId,
            'complimentary' => true,
            'reason' => 'Customer appreciation award',
        ]));
        $this->assertSame(403, $adminResponse->getStatusCode());

        $superAdminRole = Role::create(['roleName' => 'SUPER_ADMIN']);
        $this->user->update(['role' => $superAdminRole->roleId]);
        Auth::setUser($this->user->fresh());
        Mail::fake();
        $startDate = now()->startOfDay();
        $expectedExpiryDate = $startDate->copy()->addMonthsNoOverflow(2)->toDateString();

        $response = app(SubscriptionController::class)->assignManual(Request::create('/assign', 'POST', [
            'userId' => $recipient->id,
            'planId' => $plan->planId,
            'complimentary' => true,
            'startDate' => $startDate->toDateTimeString(),
            'durationMonths' => 2,
            'reason' => 'Customer appreciation award',
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['emailSent']);
        $this->assertSame($plan->planId, $recipient->fresh()->currentPlan);

        $subscription = Subscription::where('userId', $recipient->id)->firstOrFail();
        $this->assertSame('active', $subscription->status);
        $this->assertSame($expectedExpiryDate, $subscription->nextBillingDate->toDateString());
        $this->assertSame($expectedExpiryDate, $subscription->endDate->toDateString());
        $this->assertSame(0, $subscription->metadata['amount_paid']);
        $this->assertSame('complimentary_award', $subscription->metadata['payment_channel']);
        $this->assertTrue($subscription->metadata['complimentary_award']);

        Mail::assertSent(UserNotificationMail::class, function (UserNotificationMail $mail) use ($recipient) {
            return $mail->hasTo($recipient->email)
                && $mail->subjectLine === 'You have been awarded a ClickInvoice subscription'
                && str_contains($mail->messageBody, 'Professional subscription has been awarded');
        });
    }

    public function test_complimentary_award_does_not_replace_an_active_subscription(): void
    {
        $currency = Currency::create([
            'currencyName' => 'Test Naira',
            'currencyCode' => 'NGN',
            'currencySymbol' => 'N',
            'country' => 'Testland',
        ]);
        $plan = Plans::create([
            'planName' => 'Professional',
            'price' => 2500,
            'currency' => $currency->currencyId,
        ]);
        $this->user->update(['role' => Role::create(['roleName' => 'SUPER_ADMIN'])->roleId]);
        Auth::setUser($this->user->fresh());
        $activeSubscription = Subscription::create([
            'userId' => $this->user->id,
            'planId' => $plan->planId,
            'provider' => 'paystack',
            'providerSubscriptionId' => 'SUB_existing',
            'status' => 'active',
            'startDate' => now()->subMonth(),
            'nextBillingDate' => now()->addDays(5),
            'endDate' => now()->addDays(5),
        ]);
        Mail::fake();

        $response = app(SubscriptionController::class)->assignManual(Request::create('/assign', 'POST', [
            'userId' => $this->user->id,
            'planId' => $plan->planId,
            'complimentary' => true,
            'durationMonths' => 1,
            'reason' => 'Customer appreciation award',
        ]));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('active', $activeSubscription->fresh()->status);
        $this->assertSame('SUB_existing', $activeSubscription->fresh()->providerSubscriptionId);
        $this->assertSame(1, Subscription::where('userId', $this->user->id)->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }
}
