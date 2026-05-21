<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\WebPushService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendSubscriptionExpiryPushReminders extends Command
{
    protected $signature = 'push:subscription-expiry-reminders';

    protected $description = 'Send device push reminders for plans expiring in 7 days or 1 day';

    public function handle(WebPushService $webPush): int
    {
        if (!$webPush->isConfigured()) {
            $this->warn('Web push is not configured (WEBPUSH_* env vars).');

            return self::SUCCESS;
        }

        $appUrl = rtrim((string) config('services.webpush.app_url', 'https://app.clickinvoice.app'), '/');
        $sent = 0;

        foreach ([7 => '7 days', 1 => '1 day'] as $days => $label) {
            $start = Carbon::now()->addDays($days)->startOfDay();
            $end = Carbon::now()->addDays($days)->endOfDay();

            $subs = Subscription::query()
                ->with('user')
                ->where('status', 'active')
                ->where(function ($q) use ($start, $end) {
                    $q->whereBetween('endDate', [$start, $end])
                        ->orWhere(function ($q2) use ($start, $end) {
                            $q2->whereNull('endDate')
                                ->whereBetween('nextBillingDate', [$start, $end]);
                        });
                })
                ->get();

            foreach ($subs as $subscription) {
                $userId = (int) ($subscription->userId ?? 0);
                if ($userId <= 0) {
                    continue;
                }

                $count = $webPush->sendToUser($userId, [
                    'title' => 'ClickInvoice plan expiring soon',
                    'body' => "Your subscription renews or ends in {$label}. Open ClickInvoice to review your plan.",
                    'url' => $appUrl . '/dashboard/my-subscriptions/',
                    'tag' => 'plan-expiry-' . $days . '-' . $subscription->subscriptionId,
                ], 'billing');

                $sent += $count;
            }
        }

        Log::channel('daily')->info("Subscription expiry push reminders sent: {$sent}");
        $this->info("Push notifications queued/sent to devices: {$sent}");

        return self::SUCCESS;
    }
}
