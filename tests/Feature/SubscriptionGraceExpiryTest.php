<?php

namespace Tests\Feature;

use App\Console\Commands\SyncSubscriptions;
use App\Models\Currency;
use App\Models\Plans;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionBillingNotice;
use App\Services\PaystackClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SubscriptionGraceExpiryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Plans $freePlan;
    private Plans $paidPlan;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['services.paystack.secret_key' => 'sk_test_grace']);
        Http::fake();

        $currency = Currency::create([
            'currencyName' => 'Nigerian Naira',
            'currencyCode' => 'NGN',
            'currencySymbol' => 'N',
            'country' => 'Nigeria',
        ]);
        $this->freePlan = Plans::create([
            'planName' => 'Starter',
            'price' => 0,
            'currency' => $currency->currencyId,
        ]);
        $this->paidPlan = Plans::create([
            'planName' => 'Basic',
            'price' => 6600,
            'currency' => $currency->currencyId,
        ]);
        $this->user = User::create([
            'firstName' => 'Grace',
            'lastName' => 'Customer',
            'email' => 'grace-expiry@example.test',
            'phoneNumber' => '+10000000002',
            'password' => 'test-password',
            'currentPlan' => $this->paidPlan->planId,
        ]);
    }

    public function test_sync_keeps_subscription_entitled_until_grace_deadline(): void
    {
        $subscription = Subscription::create([
            'userId' => $this->user->id,
            'planId' => $this->paidPlan->planId,
            'provider' => 'paystack',
            'status' => 'past_due',
            'nextBillingDate' => now()->subDay(),
            'endDate' => now()->addDay(),
        ]);

        app(SyncSubscriptions::class)->handle(app(PaystackClient::class));

        $this->assertSame('past_due', $subscription->fresh()->status);
        $this->assertSame((int) $this->paidPlan->planId, (int) $this->user->fresh()->currentPlan);
    }

    public function test_sync_expires_after_grace_and_downgrades_only_if_no_other_entitled_plan(): void
    {
        $expiredSubscription = Subscription::create([
            'userId' => $this->user->id,
            'planId' => $this->paidPlan->planId,
            'provider' => 'paystack',
            'status' => 'past_due',
            'nextBillingDate' => now()->subDays(4),
            'endDate' => now()->subDay(),
        ]);

        app(SyncSubscriptions::class)->handle(app(PaystackClient::class));

        $this->assertSame('expired', $expiredSubscription->fresh()->status);
        $this->assertSame((int) $this->freePlan->planId, (int) $this->user->fresh()->currentPlan);
    }

    public function test_expiring_one_subscription_does_not_downgrade_user_with_another_active_plan(): void
    {
        $alternatePlan = Plans::create([
            'planName' => 'Premium',
            'price' => 13300,
            'currency' => $this->paidPlan->currency,
        ]);
        $this->user->update(['currentPlan' => $alternatePlan->planId]);

        $expiredSubscription = Subscription::create([
            'userId' => $this->user->id,
            'planId' => $this->paidPlan->planId,
            'provider' => 'paystack',
            'status' => 'past_due',
            'nextBillingDate' => now()->subDays(4),
            'endDate' => now()->subDay(),
        ]);
        Subscription::create([
            'userId' => $this->user->id,
            'planId' => $alternatePlan->planId,
            'provider' => 'paystack',
            'status' => 'active',
            'startDate' => now()->subDay(),
            'nextBillingDate' => now()->addDays(29),
        ]);

        app(SyncSubscriptions::class)->handle(app(PaystackClient::class));

        $this->assertSame('expired', $expiredSubscription->fresh()->status);
        $this->assertSame((int) $alternatePlan->planId, (int) $this->user->fresh()->currentPlan);
    }

    public function test_sync_honors_admin_access_extension_after_provider_billing_date(): void
    {
        $billingDate = now()->subDay();
        $accessThrough = now()->addMonth();
        $subscription = Subscription::create([
            'userId' => $this->user->id,
            'planId' => $this->paidPlan->planId,
            'provider' => 'paystack',
            'providerSubscriptionId' => 'SUB_admin_extension',
            'status' => 'active',
            'startDate' => now()->subMonths(2),
            'nextBillingDate' => $billingDate,
            'endDate' => $billingDate,
            'metadata' => [
                'admin_access_extension_until' => $accessThrough->toDateTimeString(),
                'admin_access_extensions' => [[
                    'months' => 1,
                    'reason' => 'Service interruption goodwill credit',
                ]],
            ],
        ]);

        app(SyncSubscriptions::class)->handle(app(PaystackClient::class));

        $fresh = $subscription->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertSame($billingDate->toDateTimeString(), $fresh->nextBillingDate->toDateTimeString());
        $this->assertTrue($fresh->hasAccessAt(now()));
        $this->assertTrue($this->user->fresh()->canCreateInvoice());
        $this->assertSame((int) $this->paidPlan->planId, (int) $this->user->fresh()->currentPlan);
        Http::assertNothingSent();
    }

    public function test_grace_expiry_disables_provider_subscription_and_keeps_email_token_encrypted(): void
    {
        Http::fake([
            'api.paystack.co/subscription/disable' => Http::response([
                'status' => true,
                'message' => 'Subscription disabled successfully',
            ]),
        ]);
        $subscription = Subscription::create([
            'userId' => $this->user->id,
            'planId' => $this->paidPlan->planId,
            'provider' => 'paystack',
            'providerSubscriptionId' => 'SUB_disable_test',
            'providerSubscriptionEmailToken' => 'provider-email-token-secret',
            'status' => 'past_due',
            'nextBillingDate' => now()->subDays(4),
            'endDate' => now()->subDay(),
        ]);

        app(SyncSubscriptions::class)->handle(app(PaystackClient::class));

        $fresh = $subscription->fresh();
        $rawToken = DB::table('subscriptions')
            ->where('subscriptionId', $subscription->subscriptionId)
            ->value('providerSubscriptionEmailToken');

        $this->assertSame('expired', $fresh->status);
        $this->assertSame('provider-email-token-secret', $fresh->providerSubscriptionEmailToken);
        $this->assertNotSame('provider-email-token-secret', $rawToken);
        $this->assertArrayNotHasKey('providerSubscriptionEmailToken', $fresh->toArray());
        $this->assertNotNull($fresh->providerSubscriptionDisabledAt);
        Http::assertSent(fn ($request) =>
            $request->url() === 'https://api.paystack.co/subscription/disable' &&
            $request['code'] === 'SUB_disable_test' &&
            $request['token'] === 'provider-email-token-secret'
        );
    }

    public function test_duplicate_failed_invoice_does_not_queue_duplicate_notice(): void
    {
        config(['services.paystack.renewal_grace_days' => 3]);
        $subscription = Subscription::create([
            'userId' => $this->user->id,
            'planId' => $this->paidPlan->planId,
            'provider' => 'paystack',
            'providerSubscriptionId' => 'SUB_notice_test',
            'status' => 'active',
        ]);
        $service = app(\App\Services\PaystackSubscriptionService::class);

        $this->assertTrue($service->recordRenewalFailure('SUB_notice_test', 'INV_notice_test'));
        $this->assertFalse($service->recordRenewalFailure('SUB_notice_test', 'INV_notice_test'));
        $this->assertSame('past_due', $subscription->fresh()->status);
        $this->assertSame(1, Notification::sent($this->user, SubscriptionBillingNotice::class)->count());
    }

    public function test_later_failed_invoice_does_not_restart_existing_grace_deadline(): void
    {
        config(['services.paystack.renewal_grace_days' => 3]);
        $subscription = Subscription::create([
            'userId' => $this->user->id,
            'planId' => $this->paidPlan->planId,
            'provider' => 'paystack',
            'providerSubscriptionId' => 'SUB_fixed_grace',
            'status' => 'active',
        ]);
        $service = app(\App\Services\PaystackSubscriptionService::class);
        $service->recordRenewalFailure('SUB_fixed_grace', 'INV_first_failure');
        $originalDeadline = $subscription->fresh()->endDate->toDateTimeString();

        $this->assertFalse($service->recordRenewalFailure('SUB_fixed_grace', 'INV_later_failure'));
        $this->assertSame($originalDeadline, $subscription->fresh()->endDate->toDateTimeString());
        $this->assertSame(1, Notification::sent($this->user, SubscriptionBillingNotice::class)->count());
    }
}
