<?php

namespace Tests\Feature;

use App\Models\SubscriptionEmailOutbox;
use App\Services\SubscriptionEmailOutboxDelivery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SubscriptionEmailOutboxDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_failed_extension_email_is_persisted_and_retried_until_sent(): void
    {
        $outbox = SubscriptionEmailOutbox::create([
            'recipient_email' => 'customer@example.test',
            'subject' => 'Your ClickInvoice access was extended',
            'message_body' => 'Your access was extended by one month.',
            'action_text' => 'View billing details',
            'action_url' => 'https://app.clickinvoice.app/dashboard/my-subscriptions/',
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => now(),
        ]);

        $mailer = Mockery::mock();
        $sendAttempts = 0;
        $mailer->shouldReceive('send')->twice()->andReturnUsing(function () use (&$sendAttempts) {
            $sendAttempts++;
            if ($sendAttempts === 1) {
                throw new RuntimeException('SMTP temporarily unavailable');
            }
        });
        Mail::shouldReceive('to')
            ->twice()
            ->with('customer@example.test')
            ->andReturn($mailer);

        $delivery = app(SubscriptionEmailOutboxDelivery::class);
        $this->assertSame('failed', $delivery->deliver($outbox->id));

        $failedAttempt = $outbox->fresh();
        $this->assertSame('failed', $failedAttempt->status);
        $this->assertSame(1, $failedAttempt->attempts);
        $this->assertNotNull($failedAttempt->next_attempt_at);
        $this->assertStringContainsString('SMTP temporarily unavailable', $failedAttempt->last_error);

        Carbon::setTestNow($failedAttempt->next_attempt_at->copy()->addSecond());
        $this->assertSame(1, $delivery->deliverPending());

        $sent = $outbox->fresh();
        $this->assertSame('sent', $sent->status);
        $this->assertSame(2, $sent->attempts);
        $this->assertNotNull($sent->sent_at);
        $this->assertNull($sent->next_attempt_at);
        $this->assertNull($sent->last_error);
    }
}