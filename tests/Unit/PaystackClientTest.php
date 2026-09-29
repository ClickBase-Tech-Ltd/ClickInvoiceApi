<?php

namespace Tests\Unit;

use App\Services\PaystackClient;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class PaystackClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paystack.secret_key' => 'sk_test_local']);
    }

    public function test_it_creates_a_monthly_plan_in_minor_units(): void
    {
        Http::fake([
            'api.paystack.co/plan' => Http::response([
                'status' => true,
                'data' => ['plan_code' => 'PLN_test'],
            ]),
        ]);

        $response = app(PaystackClient::class)->createPlan('Professional', 250000, 'ngn', 'monthly');

        $this->assertTrue($response->successful());
        Http::assertSent(fn ($request) =>
            $request->url() === 'https://api.paystack.co/plan' &&
            $request['amount'] === 250000 &&
            $request['currency'] === 'NGN' &&
            $request['interval'] === 'monthly'
        );
    }

    public function test_it_initializes_a_plan_subscription_with_reference_and_callback(): void
    {
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'data' => ['authorization_url' => 'https://checkout.paystack.com/local-test'],
            ]),
        ]);

        $response = app(PaystackClient::class)->initializeSubscription(
            'customer@example.test',
            250000,
            'NGN',
            'PLN_test',
            'ci_sub_local_reference',
            'http://localhost:3002/dashboard/subscription/success',
            ['subscription_id' => 11]
        );

        $this->assertTrue($response->successful());
        Http::assertSent(fn ($request) =>
            $request->url() === 'https://api.paystack.co/transaction/initialize' &&
            $request['plan'] === 'PLN_test' &&
            $request['reference'] === 'ci_sub_local_reference' &&
            $request['metadata']['subscription_id'] === 11
        );
    }

    public function test_it_verifies_a_transaction_by_url_encoded_reference(): void
    {
        Http::fake([
            'api.paystack.co/transaction/verify/ref%20with%20space' => Http::response([
                'status' => true,
                'data' => ['status' => 'success'],
            ]),
        ]);

        $response = app(PaystackClient::class)->verifyTransaction('ref with space');

        $this->assertTrue($response->successful());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/verify/ref%20with%20space'));
    }

    public function test_it_requests_a_hosted_subscription_management_link(): void
    {
        Http::fake([
            'api.paystack.co/subscription/SUB_test/manage/link' => Http::response([
                'status' => true,
                'data' => ['link' => 'https://paystack.com/manage/subscription-test'],
            ]),
        ]);

        $response = app(PaystackClient::class)->getSubscriptionManagementLink('SUB_test');

        $this->assertTrue($response->successful());
        $this->assertSame('https://paystack.com/manage/subscription-test', $response->json('data.link'));
        Http::assertSent(fn ($request) =>
            $request->method() === 'GET' &&
            $request->url() === 'https://api.paystack.co/subscription/SUB_test/manage/link'
        );
    }

    public function test_it_disables_a_subscription_with_code_and_email_token(): void
    {
        Http::fake([
            'api.paystack.co/subscription/disable' => Http::response([
                'status' => true,
                'message' => 'Subscription disabled successfully',
            ]),
        ]);

        $response = app(PaystackClient::class)->disableSubscription('SUB_test', 'email-token-test');

        $this->assertTrue($response->successful());
        Http::assertSent(fn ($request) =>
            $request->method() === 'POST' &&
            $request->url() === 'https://api.paystack.co/subscription/disable' &&
            $request['code'] === 'SUB_test' &&
            $request['token'] === 'email-token-test'
        );
    }

    public function test_it_validates_paystack_sha512_webhook_signatures(): void
    {
        $payload = '{"event":"charge.success"}';
        $signature = hash_hmac('sha512', $payload, 'sk_test_local');
        $client = app(PaystackClient::class);

        $this->assertTrue($client->hasValidSignature($payload, $signature));
        $this->assertFalse($client->hasValidSignature($payload, 'invalid'));
        $this->assertFalse($client->hasValidSignature($payload, null));
    }

    public function test_local_environment_rejects_live_credentials_before_network_request(): void
    {
        config([
            'app.env' => 'local',
            'services.paystack.secret_key' => 'sk_live_not_a_real_key',
        ]);
        Http::fake();

        try {
            app(PaystackClient::class)->verifyTransaction('local_test_reference');
            $this->fail('A live key must not be used from the local environment.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Paystack credentials do not match the application environment.',
                $exception->getMessage()
            );
        }

        Http::assertNothingSent();
    }
}