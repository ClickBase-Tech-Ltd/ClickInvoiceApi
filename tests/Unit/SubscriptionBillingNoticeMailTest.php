<?php

namespace Tests\Unit;

use App\Mail\UserNotificationMail;
use App\Models\User;
use App\Notifications\SubscriptionBillingNotice;
use Tests\TestCase;

class SubscriptionBillingNoticeMailTest extends TestCase
{
    public function test_payment_confirmation_uses_branded_clickinvoice_template_and_billing_action(): void
    {
        $user = new User(['firstName' => 'Ada', 'email' => 'ada@example.test']);
        $notice = new SubscriptionBillingNotice(
            'payment_confirmed',
            'Professional',
            '2500.00',
            'NGN',
            'October 30, 2026'
        );

        $mail = $notice->toMail($user);

        $this->assertInstanceOf(UserNotificationMail::class, $mail);
        $html = $mail->render();
        $this->assertStringContainsString('ClickInvoice', $html);
        $this->assertStringContainsString('Hello Ada,', $html);
        $this->assertStringContainsString('NGN 2500.00', $html);
        $this->assertStringContainsString('October 30, 2026', $html);
        $this->assertStringContainsString('View billing details', $html);
        $this->assertStringContainsString('/dashboard/plans/', $html);
    }

    public function test_renewal_failure_uses_branded_template_and_renewal_action(): void
    {
        $user = new User(['firstName' => 'Ada', 'email' => 'ada@example.test']);
        $notice = new SubscriptionBillingNotice(
            'renewal_failed',
            'Professional',
            '2500.00',
            'NGN',
            'October 3, 2026'
        );

        $mail = $notice->toMail($user);

        $this->assertInstanceOf(UserNotificationMail::class, $mail);
        $html = $mail->render();
        $this->assertStringContainsString('Paystack could not collect', $html);
        $this->assertStringContainsString('October 3, 2026', $html);
        $this->assertStringContainsString('Renew your plan', $html);
        $this->assertStringContainsString('/dashboard/plans/', $html);
    }
}