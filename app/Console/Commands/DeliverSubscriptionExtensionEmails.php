<?php

namespace App\Console\Commands;

use App\Services\SubscriptionEmailOutboxDelivery;
use Illuminate\Console\Command;

class DeliverSubscriptionExtensionEmails extends Command
{
    protected $signature = 'subscriptions:deliver-extension-emails';

    protected $description = 'Deliver and retry pending subscription access extension emails';

    public function handle(SubscriptionEmailOutboxDelivery $delivery): int
    {
        $sent = $delivery->deliverPending();
        $this->info("Subscription extension emails sent: {$sent}");

        return self::SUCCESS;
    }
}