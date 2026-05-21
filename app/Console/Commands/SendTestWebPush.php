<?php

namespace App\Console\Commands;

use App\Services\WebPushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendTestWebPush extends Command
{
    protected $signature = 'push:test {userId : User id to send a test notification to}';

    protected $description = 'Send a test device push to all subscriptions for a user (local QA)';

    public function handle(WebPushService $webPush): int
    {
        if (!$webPush->isConfigured()) {
            $this->error('WEBPUSH_PUBLIC_KEY and WEBPUSH_PRIVATE_KEY must be set in .env');
            $this->line('Run: php artisan push:generate-vapid-keys');

            return self::FAILURE;
        }

        $userId = (int) $this->argument('userId');
        if ($userId <= 0) {
            $this->error('Invalid userId');

            return self::FAILURE;
        }

        $webPush->ensureSubscriptionsTable();
        $count = DB::table('push_subscriptions')->where('user_id', $userId)->count();

        if ($count === 0) {
            $this->warn("No push subscriptions for user {$userId}.");
            $this->line('1. Open http://localhost:3002 and sign in');
            $this->line('2. Click "Enable notifications" and allow in the browser');
            $this->line('3. Run this command again');

            return self::FAILURE;
        }

        $appUrl = rtrim((string) config('services.webpush.app_url', 'http://localhost:3002'), '/');
        $sent = $webPush->sendToUser($userId, [
            'title' => 'ClickInvoice test',
            'body' => 'Local push is working. Tap to open the app.',
            'url' => $appUrl . '/dashboard/',
            'tag' => 'local-test-' . time(),
        ], 'announcement');

        $this->info("Sent to {$sent} device(s) for user {$userId}.");

        return $sent > 0 ? self::SUCCESS : self::FAILURE;
    }
}
