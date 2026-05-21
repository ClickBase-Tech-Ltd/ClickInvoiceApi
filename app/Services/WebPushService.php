<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    public function ensureSubscriptionsTable(): void
    {
        DB::statement(
            "CREATE TABLE IF NOT EXISTS push_subscriptions (
                id CHAR(36) NOT NULL,
                user_id BIGINT(20) UNSIGNED NOT NULL,
                endpoint TEXT NOT NULL,
                p256dh VARCHAR(255) NOT NULL,
                auth VARCHAR(255) NOT NULL,
                user_agent VARCHAR(512) NULL,
                platform VARCHAR(64) NULL,
                announcements_enabled TINYINT(1) NOT NULL DEFAULT 1,
                billing_enabled TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_push_endpoint (endpoint(500)),
                KEY idx_push_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function isConfigured(): bool
    {
        $public = trim((string) config('services.webpush.public_key'));
        $private = trim((string) config('services.webpush.private_key'));

        return $public !== '' && $private !== '';
    }

    public function publicKey(): ?string
    {
        $key = trim((string) config('services.webpush.public_key'));

        return $key !== '' ? $key : null;
    }

    /**
     * @param  array{title:string,body?:string,url?:string,tag?:string,notificationId?:string}  $payload
     */
    public function sendToUser(int $userId, array $payload, string $category = 'announcement'): int
    {
        if (!$this->isConfigured() || $userId <= 0) {
            return 0;
        }

        $this->ensureSubscriptionsTable();

        $enabledColumn = $category === 'billing' ? 'billing_enabled' : 'announcements_enabled';

        $rows = DB::table('push_subscriptions')
            ->where('user_id', $userId)
            ->where($enabledColumn, 1)
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $sent = 0;
        foreach ($rows as $row) {
            if ($this->sendToRow($row, $payload)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * @param  int[]  $userIds
     * @param  array{title:string,body?:string,url?:string,tag?:string,notificationId?:string}  $payload
     */
    public function sendToUsers(array $userIds, array $payload, string $category = 'announcement'): int
    {
        $total = 0;
        foreach (array_unique(array_map('intval', $userIds)) as $userId) {
            if ($userId > 0) {
                $total += $this->sendToUser($userId, $payload, $category);
            }
        }

        return $total;
    }

    /**
     * @param  object  $row
     * @param  array{title:string,body?:string,url?:string,tag?:string,notificationId?:string}  $payload
     */
    private function sendToRow(object $row, array $payload): bool
    {
        try {
            $webPush = $this->client();
            $subscription = Subscription::create([
                'endpoint' => (string) $row->endpoint,
                'keys' => [
                    'p256dh' => (string) $row->p256dh,
                    'auth' => (string) $row->auth,
                ],
            ]);

            $appUrl = rtrim((string) config('services.webpush.app_url', 'https://app.clickinvoice.app'), '/');
            $url = (string) ($payload['url'] ?? '/dashboard/');
            if (!str_starts_with($url, 'http')) {
                $url = $appUrl . (str_starts_with($url, '/') ? $url : '/' . $url);
            }

            $body = json_encode([
                'title' => (string) ($payload['title'] ?? 'ClickInvoice'),
                'body' => (string) ($payload['body'] ?? ''),
                'url' => $url,
                'tag' => (string) ($payload['tag'] ?? 'clickinvoice'),
                'notificationId' => (string) ($payload['notificationId'] ?? ''),
                'silent' => false,
            ], JSON_UNESCAPED_UNICODE);

            $report = $webPush->sendOneNotification(
                $subscription,
                $body,
                ['TTL' => 86400, 'urgency' => 'high']
            );

            if ($report->isSuccess()) {
                DB::table('push_subscriptions')
                    ->where('id', $row->id)
                    ->update(['updated_at' => now()]);

                return true;
            }

            if ($report->isSubscriptionExpired()) {
                DB::table('push_subscriptions')->where('id', $row->id)->delete();
            }

            Log::channel('daily')->warning('Web push failed', [
                'endpoint' => Str::limit((string) $row->endpoint, 80),
                'reason' => $report->getReason(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('daily')->warning('Web push exception', [
                'message' => $e->getMessage(),
            ]);
        }

        return false;
    }

    private function client(): WebPush
    {
        return new WebPush([
            'VAPID' => [
                'subject' => (string) config('services.webpush.subject', 'mailto:support@clickinvoice.app'),
                'publicKey' => (string) config('services.webpush.public_key'),
                'privateKey' => (string) config('services.webpush.private_key'),
            ],
        ]);
    }
}
