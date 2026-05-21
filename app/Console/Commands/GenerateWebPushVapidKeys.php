<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateWebPushVapidKeys extends Command
{
    protected $signature = 'push:generate-vapid-keys';

    protected $description = 'Generate VAPID public/private keys for Web Push (.env)';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->info('Add these to your API .env:');
        $this->line('');
        $this->line('WEBPUSH_PUBLIC_KEY=' . $keys['publicKey']);
        $this->line('WEBPUSH_PRIVATE_KEY=' . $keys['privateKey']);
        $this->line('WEBPUSH_SUBJECT=mailto:support@clickinvoice.app');
        $this->line('');
        $this->info('Add the public key to the frontend .env.production:');
        $this->line('NEXT_PUBLIC_WEBPUSH_PUBLIC_KEY=' . $keys['publicKey']);

        return self::SUCCESS;
    }
}
