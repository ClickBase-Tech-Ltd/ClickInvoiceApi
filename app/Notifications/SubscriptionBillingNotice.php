<?php

namespace App\Notifications;

use App\Mail\UserNotificationMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SubscriptionBillingNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $type,
        public string $planName,
        public string $amount,
        public string $currency,
        public string $effectiveDate
    ) {
        $this->onQueue('mail');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): UserNotificationMail
    {
        $plansUrl = app()->environment(['local', 'testing'])
            ? 'http://localhost:3002/dashboard/plans/'
            : rtrim((string) config('clickinvoice.app_url'), '/') . '/dashboard/plans/';
        $greeting = 'Hello ' . ($notifiable->firstName ?: 'there') . ',';

        if ($this->type === 'renewal_failed') {
            return new UserNotificationMail(
                user: $notifiable,
                subjectLine: 'Payment needs attention for your ClickInvoice plan',
                messageBody: "{$greeting}\n\n"
                    . "Paystack could not collect {$this->currency} {$this->amount} for your {$this->planName} subscription.\n\n"
                    . "Your paid access remains available until {$this->effectiveDate}. Paystack does not automatically retry this subscription charge.\n\n"
                    . 'If you have already renewed, you can ignore this message.',
                actionText: 'Renew your plan',
                actionUrl: $plansUrl
            );
        }

        $isRenewal = $this->type === 'renewal_success';

        return new UserNotificationMail(
            user: $notifiable,
            subjectLine: $isRenewal
                ? 'Your ClickInvoice subscription renewal is confirmed'
                : 'Your ClickInvoice subscription payment is confirmed',
            messageBody: "{$greeting}\n\n"
                . "We received {$this->currency} {$this->amount} for your {$this->planName} subscription.\n\n"
                . "Your next billing date is {$this->effectiveDate}.\n\n"
                . 'Thank you for choosing ClickInvoice.',
            actionText: 'View billing details',
            actionUrl: $plansUrl
        );
    }
}