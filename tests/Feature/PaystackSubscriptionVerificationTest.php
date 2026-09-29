<?php

namespace Tests\Feature;

use App\Http\Controllers\SubscriptionController;
use App\Models\Payment;
use App\Models\Plans;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionBillingNotice;
use App\Services\PaystackClient;
use App\Services\PaystackSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PaystackSubscriptionVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Plans $plan;
    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paystack.secret_key' => 'sk_test_feature']);
        config(['services.paystack.renewal_grace_days' => 3]);
        Notification::fake();

        $currency = \App\Models\Currency::create([
            'currencyName' => 'Nigerian Naira',
            'currencyCode' => 'NGN',
            'currencySymbol' => '₦',
            'country' => 'Nigeria',
        ]);
        $freePlan = Plans::create([
            'planName' => 'Starter',
            'price' => 0,
            'currency' => $currency->currencyId,
        ]);
        $this->plan = Plans::create([
            'planName' => 'Professional',
            'price' => 2500,
            'currency' => $currency->currencyId,
            'paystackTestPlanCode' => 'PLN_test',
        ]);
        $this->user = User::create([
            'firstName' => 'Test',
            'lastName' => 'Customer',
            'email' => 'customer@example.test',
            'phoneNumber' => '+2348000000000',
            'password' => 'test-password',
            'currentPlan' => $freePlan->planId,
        ]);
        $subscription = Subscription::create([
            'userId' => $this->user->id,
            'planId' => $this->plan->planId,
            'provider' => 'paystack',
            'status' => 'pending',
        ]);
        $this->payment = Payment::create([
            'subscriptionId' => $subscription->subscriptionId,
            'provider' => 'paystack',
            'providerReference' => 'ci_sub_test_reference',
            'userId' => $this->user->id,
            'amount' => '2500.00',
            'currency' => 'NGN',
            'status' => 'pending',
        ]);
    }

    public function test_verified_payment_activates_subscription_and_is_idempotent(): void
    {
        Http::fake([
            'api.paystack.co/transaction/verify/ci_sub_test_reference' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 998877,
                    'reference' => 'ci_sub_test_reference',
                    'status' => 'success',
                    'amount' => 250000,
                    'currency' => 'NGN',
                    'customer' => ['email' => 'customer@example.test', 'customer_code' => 'CUS_test'],
                    'subscription' => ['subscription_code' => 'SUB_test'],
                ],
            ]),
        ]);

        $service = app(PaystackSubscriptionService::class);
        $result = $service->verifyAndApply('ci_sub_test_reference', app(PaystackClient::class));
        $again = $service->verifyAndApply('ci_sub_test_reference', app(PaystackClient::class));

        $this->assertSame('successful', $result['status']);
        $this->assertSame('successful', $again['status']);
        $this->assertSame('active', $this->payment->subscription->fresh()->status);
        $this->assertSame('successful', $this->payment->fresh()->status);
        $this->assertSame((int) $this->plan->planId, (int) $this->user->fresh()->currentPlan);
        $this->assertSame('SUB_test', $this->payment->subscription->fresh()->providerSubscriptionId);
        Notification::assertSentTo($this->user, SubscriptionBillingNotice::class, fn ($notice) =>
            $notice->type === 'payment_confirmed'
        );
        $this->assertCount(1, Notification::sent($this->user, SubscriptionBillingNotice::class));
        Http::assertSentCount(1);
    }

    public function test_later_initial_webhook_backfills_subscription_code_without_raw_authorization_data(): void
    {
        $nextPaymentDate = now()->addMonth()->toIso8601String();
        Http::fake([
            'api.paystack.co/transaction/verify/ci_sub_test_reference' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 998877,
                    'reference' => 'ci_sub_test_reference',
                    'status' => 'success',
                    'amount' => 250000,
                    'currency' => 'NGN',
                    'paid_at' => now()->toIso8601String(),
                    'customer' => ['email' => 'customer@example.test'],
                ],
            ]),
        ]);

        $service = app(PaystackSubscriptionService::class);
        $service->verifyAndApply('ci_sub_test_reference', app(PaystackClient::class));
        $this->assertNull($this->payment->subscription->fresh()->providerSubscriptionId);

        $service->verifyAndApply('ci_sub_test_reference', app(PaystackClient::class), [
            'subscription' => [
                'subscription_code' => 'SUB_from_webhook',
                'next_payment_date' => $nextPaymentDate,
                'email_token' => 'must-not-be-persisted',
            ],
            'customer' => ['customer_code' => 'CUS_from_webhook'],
            'authorization' => ['authorization_code' => 'must-not-be-persisted'],
        ]);

        $this->assertSame('SUB_from_webhook', $this->payment->subscription->fresh()->providerSubscriptionId);
        $this->assertSame('CUS_from_webhook', $this->payment->subscription->fresh()->providerCustomerCode);
        $this->assertTrue($this->payment->subscription->fresh()->nextBillingDate->equalTo($nextPaymentDate));
        $this->assertArrayNotHasKey('authorization', $this->payment->fresh()->responseData);
        $this->assertArrayNotHasKey('email_token', $this->payment->fresh()->responseData);
        Http::assertSentCount(1);
    }

    public function test_amount_mismatch_does_not_activate_subscription(): void
    {
        Http::fake([
            'api.paystack.co/transaction/verify/ci_sub_test_reference' => Http::response([
                'status' => true,
                'data' => [
                    'reference' => 'ci_sub_test_reference',
                    'status' => 'success',
                    'amount' => 100,
                    'currency' => 'NGN',
                    'customer' => ['email' => 'customer@example.test'],
                ],
            ]),
        ]);

        $result = app(PaystackSubscriptionService::class)
            ->verifyAndApply('ci_sub_test_reference', app(PaystackClient::class));

        $this->assertSame('mismatch', $result['status']);
        $this->assertSame('pending', $this->payment->subscription->fresh()->status);
        $this->assertSame('pending', $this->payment->fresh()->status);
    }

    public function test_verified_recurring_invoice_is_applied_once_and_advances_next_billing_date(): void
    {
        $subscription = $this->payment->subscription;
        $subscription->update([
            'provider' => 'paystack',
            'providerSubscriptionId' => 'SUB_test',
            'status' => 'active',
            'startDate' => now()->subMonth(),
            'nextBillingDate' => now()->subDay(),
        ]);
        $this->payment->update(['status' => 'successful']);
        $nextPaymentDate = now()->addMonth()->toIso8601String();

        Http::fake([
            'api.paystack.co/transaction/verify/renewal_reference' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 887766,
                    'reference' => 'renewal_reference',
                    'status' => 'success',
                    'amount' => 250000,
                    'currency' => 'NGN',
                    'paid_at' => now()->toIso8601String(),
                    'customer' => ['email' => 'customer@example.test'],
                    'subscription' => ['subscription_code' => 'SUB_test'],
                ],
            ]),
        ]);

        $service = app(PaystackSubscriptionService::class);
        $result = $service->verifyAndApplyRenewal(
            'renewal_reference',
            'SUB_test',
            $nextPaymentDate,
            'INV_test',
            app(PaystackClient::class)
        );
        $again = $service->verifyAndApplyRenewal(
            'renewal_reference',
            'SUB_test',
            $nextPaymentDate,
            'INV_test',
            app(PaystackClient::class)
        );

        $this->assertSame('successful', $result['status']);
        $this->assertSame('successful', $again['status']);
        $this->assertSame(2, Payment::where('subscriptionId', $subscription->subscriptionId)->count());
        $this->assertSame('active', $subscription->fresh()->status);
        $this->assertTrue($subscription->fresh()->nextBillingDate->equalTo($nextPaymentDate));
        $this->assertSame('renewal_reference', $subscription->fresh()->metadata['last_renewal_reference']);
        Notification::assertSentTo($this->user, SubscriptionBillingNotice::class, fn ($notice) =>
            $notice->type === 'renewal_success'
        );
        $this->assertCount(1, Notification::sent($this->user, SubscriptionBillingNotice::class));
        Http::assertSentCount(1);
    }

    public function test_recurring_amount_mismatch_does_not_extend_subscription(): void
    {
        $subscription = $this->payment->subscription;
        $oldNextBillingDate = now()->subDay();
        $subscription->update([
            'provider' => 'paystack',
            'providerSubscriptionId' => 'SUB_test',
            'status' => 'active',
            'nextBillingDate' => $oldNextBillingDate,
        ]);
        $this->payment->update(['status' => 'successful']);
        Http::fake([
            'api.paystack.co/transaction/verify/renewal_mismatch' => Http::response([
                'status' => true,
                'data' => [
                    'reference' => 'renewal_mismatch',
                    'status' => 'success',
                    'amount' => 100,
                    'currency' => 'NGN',
                    'customer' => ['email' => 'customer@example.test'],
                    'subscription' => ['subscription_code' => 'SUB_test'],
                ],
            ]),
        ]);

        $result = app(PaystackSubscriptionService::class)->verifyAndApplyRenewal(
            'renewal_mismatch',
            'SUB_test',
            now()->addMonth()->toIso8601String(),
            'INV_mismatch',
            app(PaystackClient::class)
        );

        $this->assertSame('mismatch', $result['status']);
        $this->assertSame(1, Payment::where('subscriptionId', $subscription->subscriptionId)->count());
        $this->assertSame(
            $oldNextBillingDate->toDateTimeString(),
            $subscription->fresh()->nextBillingDate->toDateTimeString()
        );
    }

    public function test_failed_renewal_enters_grace_without_changing_paid_plan(): void
    {
        $subscription = $this->payment->subscription;
        $subscription->update([
            'provider' => 'paystack',
            'providerSubscriptionId' => 'SUB_test',
            'status' => 'active',
        ]);

        $recorded = app(PaystackSubscriptionService::class)
            ->recordRenewalFailure('SUB_test', 'INV_failed');

        $this->assertTrue($recorded);
        $freshSubscription = $subscription->fresh();
        $this->assertSame('past_due', $freshSubscription->status);
        $this->assertSame('INV_failed', $subscription->fresh()->metadata['last_renewal_failure']['invoice_code']);
        $this->assertArrayNotHasKey('customer', $subscription->fresh()->metadata['last_renewal_failure']);
        $graceEndsAt = \Carbon\Carbon::parse($freshSubscription->metadata['last_renewal_failure']['grace_ends_at']);
        $this->assertTrue($graceEndsAt->greaterThan(now()));
        Notification::assertSentTo($this->user, SubscriptionBillingNotice::class, fn ($notice) =>
            $notice->type === 'renewal_failed'
        );
    }

    public function test_signed_invoice_update_webhook_applies_recurring_payment_once(): void
    {
        $subscription = $this->payment->subscription;
        $subscription->update([
            'provider' => 'paystack',
            'providerSubscriptionId' => 'SUB_test',
            'status' => 'active',
            'nextBillingDate' => now()->subDay(),
        ]);
        $this->payment->update(['status' => 'successful']);
        $nextPaymentDate = now()->addMonth()->toIso8601String();
        $payload = [
            'event' => 'invoice.update',
            'data' => [
                'invoice_code' => 'INV_test',
                'status' => 'success',
                'paid' => true,
                'subscription' => [
                    'subscription_code' => 'SUB_test',
                    'next_payment_date' => $nextPaymentDate,
                ],
                'transaction' => ['reference' => 'webhook_renewal_reference'],
            ],
        ];
        $rawPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha512', $rawPayload, 'sk_test_feature');
        Http::fake([
            'api.paystack.co/transaction/verify/webhook_renewal_reference' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 778899,
                    'reference' => 'webhook_renewal_reference',
                    'status' => 'success',
                    'amount' => 250000,
                    'currency' => 'NGN',
                    'paid_at' => now()->toIso8601String(),
                    'customer' => ['email' => 'customer@example.test'],
                    'subscription' => ['subscription_code' => 'SUB_test'],
                ],
            ]),
        ]);

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ];
        $first = $this->call('POST', '/api/paystack/webhook', [], [], [], $server, $rawPayload);
        $second = $this->call('POST', '/api/paystack/webhook', [], [], [], $server, $rawPayload);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame(2, Payment::where('subscriptionId', $subscription->subscriptionId)->count());
        $this->assertTrue($subscription->fresh()->nextBillingDate->equalTo($nextPaymentDate));
        Http::assertSentCount(1);
    }

    public function test_signed_failed_invoice_webhook_records_minimal_failure_metadata(): void
    {
        $subscription = $this->payment->subscription;
        $subscription->update([
            'provider' => 'paystack',
            'providerSubscriptionId' => 'SUB_test',
            'status' => 'active',
        ]);
        $payload = [
            'event' => 'invoice.payment_failed',
            'data' => [
                'invoice_code' => 'INV_failed_webhook',
                'subscription' => ['subscription_code' => 'SUB_test'],
                'customer' => ['email' => 'customer@example.test'],
            ],
        ];
        $rawPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha512', $rawPayload, 'sk_test_feature');

        $response = $this->call(
            'POST',
            '/api/paystack/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
            ],
            $rawPayload
        );

        $response->assertOk();
        $metadata = $subscription->fresh()->metadata;
        $this->assertSame('INV_failed_webhook', $metadata['last_renewal_failure']['invoice_code']);
        $this->assertArrayNotHasKey('customer', $metadata['last_renewal_failure']);
        Http::assertNothingSent();
    }

    public function test_local_checkout_uses_test_plan_code_and_local_callback(): void
    {
        Auth::login($this->user);
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => ['authorization_url' => 'https://checkout.paystack.com/sandbox'],
            ]),
        ]);

        $response = app(SubscriptionController::class)->create(
            Request::create('/subscribe/' . $this->plan->planId, 'POST'),
            $this->plan->planId,
            app(PaystackClient::class)
        );

        $this->assertSame(200, $response->getStatusCode());
        Http::assertSent(fn ($request) =>
            $request->url() === 'https://api.paystack.co/transaction/initialize' &&
            $request['plan'] === 'PLN_test' &&
            str_starts_with($request['callback_url'], 'http://localhost:3002/dashboard/subscription/success?reference=') &&
            $request['currency'] === 'NGN' &&
            $request['amount'] === 250000
        );
    }

    public function test_expired_active_row_does_not_block_starting_a_new_plan_checkout(): void
    {
        Auth::login($this->user);
        $expiredSubscription = Subscription::create([
            'userId' => $this->user->id,
            'planId' => $this->plan->planId,
            'provider' => 'paystack',
            'status' => 'active',
            'startDate' => now()->subMonths(3),
            'nextBillingDate' => now()->subMonth(),
            'endDate' => now()->subMonths(2),
        ]);
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => ['authorization_url' => 'https://checkout.paystack.com/renewal'],
            ]),
        ]);

        $response = app(SubscriptionController::class)->renew(
            Request::create('/subscriptions/' . $expiredSubscription->subscriptionId . '/renew', 'POST'),
            $expiredSubscription->subscriptionId,
            app(PaystackClient::class)
        );

        $this->assertSame(200, $response->getStatusCode());
        Http::assertSent(fn ($request) =>
            $request->url() === 'https://api.paystack.co/transaction/initialize' &&
            $request['plan'] === 'PLN_test'
        );
    }

    public function test_past_due_renewal_uses_existing_subscription_management_link(): void
    {
        Auth::login($this->user);
        $subscription = $this->payment->subscription;
        $subscription->update([
            'provider' => 'paystack',
            'providerSubscriptionId' => 'SUB_existing',
            'status' => 'past_due',
            'endDate' => now()->addDays(2),
            'nextBillingDate' => now()->subDay(),
        ]);
        Http::fake([
            'api.paystack.co/subscription/SUB_existing/manage/link' => Http::response([
                'status' => true,
                'data' => ['link' => 'https://paystack.com/manage/existing-subscription'],
            ]),
        ]);

        $response = app(SubscriptionController::class)->renew(
            Request::create('/subscriptions/' . $subscription->subscriptionId . '/renew', 'POST'),
            $subscription->subscriptionId,
            app(PaystackClient::class)
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('https://paystack.com/manage/existing-subscription', $response->getData(true)['management_url']);
        $this->assertSame(1, Subscription::where('userId', $this->user->id)->count());
        Http::assertSentCount(1);
    }

    public function test_local_checkout_rejects_live_key_before_creating_records_or_calling_paystack(): void
    {
        config([
            'app.env' => 'local',
            'services.paystack.secret_key' => 'sk_live_not_a_real_key',
        ]);
        Auth::login($this->user);
        Http::fake();
        $subscriptionCount = Subscription::count();
        $paymentCount = Payment::count();

        $response = app(SubscriptionController::class)->create(
            Request::create('/subscribe/' . $this->plan->planId, 'POST'),
            $this->plan->planId,
            app(PaystackClient::class)
        );

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('paystack_environment_mismatch', $response->getData(true)['code']);
        $this->assertStringContainsString('No payment was started', $response->getData(true)['error']);
        $this->assertSame($subscriptionCount, Subscription::count());
        $this->assertSame($paymentCount, Payment::count());
        Http::assertNothingSent();
    }

    public function test_provider_connection_failure_marks_checkout_records_failed(): void
    {
        Auth::login($this->user);
        Http::fake(function () {
            throw new ConnectionException('Paystack connection timed out.');
        });

        $response = app(SubscriptionController::class)->create(
            Request::create('/subscribe/' . $this->plan->planId, 'POST'),
            $this->plan->planId,
            app(PaystackClient::class)
        );

        $this->assertSame(502, $response->getStatusCode());
        $failedSubscription = Subscription::query()
            ->where('userId', $this->user->id)
            ->where('planId', $this->plan->planId)
            ->where('provider', 'paystack')
            ->latest('subscriptionId')
            ->firstOrFail();
        $this->assertSame('failed', $failedSubscription->status);
        $this->assertSame('failed', Payment::where('subscriptionId', $failedSubscription->subscriptionId)->value('status'));
    }
}