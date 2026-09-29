<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
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

    public function toMail(object $notifiable): MailMessage
    {
        $plansUrl = app()->environment(['local', 'testing'])
            ? 'http://localhost:3002/dashboard/plans/'
            : rtrim((string) config('clickinvoice.app_url'), '/') . '/dashboard/plans/';
        $greeting = 'Hello ' . ($notifiable->firstName ?: 'there');

        if ($this->type === 'renewal_failed') {
            return (new MailMessage())
                ->subject('Payment needs attention for your ClickInvoice plan')
                ->greeting($greeting)
                ->line("Paystack could not collect {$this->currency} {$this->amount} for your {$this->planName} subscription.")
                ->line("Your paid access remains available until {$this->effectiveDate}. Paystack does not automatically retry this subscription charge.")
                ->action('Renew your plan', $plansUrl)
                ->line('If you have already renewed, you can ignore this message.');
        }

        return (new MailMessage())
            ->subject('Your ClickInvoice subscription payment is confirmed')
            ->greeting($greeting)
            ->line("We received {$this->currency} {$this->amount} for your {$this->planName} subscription.")
            ->line("Your next billing date is {$this->effectiveDate}.")
            ->action('View billing details', $plansUrl);
    }
}