<?php

namespace App\Services;

use App\Mail\UserNotificationMail;
use App\Models\SubscriptionEmailOutbox;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SubscriptionEmailOutboxDelivery
{
    public function deliver(int $outboxId): string
    {
        $claimed = DB::transaction(function () use ($outboxId) {
            $message = SubscriptionEmailOutbox::query()
                ->lockForUpdate()
                ->find($outboxId);

            if (
                !$message ||
                !in_array($message->status, ['pending', 'failed'], true) ||
                ($message->next_attempt_at && $message->next_attempt_at->isFuture())
            ) {
                return null;
            }

            $message->update([
                'status' => 'sending',
                'attempts' => $message->attempts + 1,
                'last_error' => null,
            ]);

            return $message->fresh();
        });

        if (!$claimed) {
            return 'not_pending';
        }

        try {
            Mail::to($claimed->recipient_email)->send(new UserNotificationMail(
                user: new User(['email' => $claimed->recipient_email]),
                subjectLine: $claimed->subject,
                messageBody: $claimed->message_body,
                actionText: $claimed->action_text ?? 'Go to ClickInvoice',
                actionUrl: $claimed->action_url
            ));

            $claimed->update([
                'status' => 'sent',
                'sent_at' => now(),
                'next_attempt_at' => null,
                'last_error' => null,
            ]);

            return 'sent';
        } catch (Throwable $exception) {
            $attempts = $claimed->attempts;
            $retryDelayMinutes = min(60 * 6, 2 ** min($attempts, 8));

            $claimed->update([
                'status' => 'failed',
                'next_attempt_at' => now()->addMinutes($retryDelayMinutes),
                'last_error' => mb_substr($exception->getMessage(), 0, 4000),
            ]);

            Log::warning('Subscription extension email delivery failed', [
                'outbox_id' => $claimed->id,
                'subscription_id' => $claimed->subscription_id,
                'attempts' => $attempts,
                'error' => $exception->getMessage(),
            ]);

            return 'failed';
        }
    }

    public function deliverPending(int $limit = 50): int
    {
        SubscriptionEmailOutbox::query()
            ->where('status', 'sending')
            ->where('updated_at', '<=', now()->subMinutes(10))
            ->update([
                'status' => 'pending',
                'next_attempt_at' => now(),
            ]);

        $messageIds = SubscriptionEmailOutbox::query()
            ->whereIn('status', ['pending', 'failed'])
            ->where(function ($query) {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $sent = 0;
        foreach ($messageIds as $messageId) {
            if ($this->deliver((int) $messageId) === 'sent') {
                $sent++;
            }
        }

        return $sent;
    }
}