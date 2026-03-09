<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InAppNotificationController extends Controller
{
    private function ensureNotificationsTable(): void
    {
        DB::statement(
            "CREATE TABLE IF NOT EXISTS in_app_notifications (
                id CHAR(36) NOT NULL,
                user_id BIGINT(20) UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                type VARCHAR(16) NOT NULL DEFAULT 'info',
                read_at DATETIME NULL,
                metadata LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_notifications_user_created (user_id, created_at),
                KEY idx_notifications_user_read (user_id, read_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function normalizeType($value): string
    {
        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['success', 'warning', 'error'], true) ? $normalized : 'info';
    }

    private function resolveOwnerUserId(Request $request, ?string $directOwner = null): ?string
    {
        $direct = trim((string) ($directOwner ?? ''));
        if ($direct !== '') {
            $exists = DB::table('users')->where('id', $direct)->exists();
            if ($exists) {
                return $direct;
            }
        }

        $emailHint = strtolower(trim((string) $request->header('x-user-email', '')));
        if ($emailHint !== '') {
            $byEmail = DB::table('users')
                ->whereRaw('TRIM(LOWER(email)) = ?', [$emailHint])
                ->value('id');
            if ($byEmail !== null) {
                return (string) $byEmail;
            }

            $byEmailLoose = DB::table('users')
                ->whereRaw('LOWER(email) LIKE ?', ["{$emailHint}%"])
                ->value('id');
            if ($byEmailLoose !== null) {
                return (string) $byEmailLoose;
            }
        }

        if (auth()->check()) {
            return (string) auth()->id();
        }

        return null;
    }

    private function toResponseItem(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'title' => (string) ($row->title ?? 'Notification'),
            'message' => (string) ($row->message ?? ''),
            'type' => $this->normalizeType($row->type ?? 'info'),
            'createdAt' => $row->created_at,
            'read' => !empty($row->read_at),
        ];
    }

    public function index(Request $request)
    {
        try {
            $this->ensureNotificationsTable();

            $ownerUserId = $this->resolveOwnerUserId($request, (string) $request->query('ownerUserId', ''));
            if (!$ownerUserId) {
                return response()->json(['error' => 'ownerUserId required'], 400);
            }

            $limitRaw = (int) $request->query('limit', 100);
            $limit = max(1, min(200, $limitRaw));

            $rows = DB::table('in_app_notifications')
                ->select(['id', 'user_id', 'title', 'message', 'type', 'read_at', 'created_at'])
                ->where('user_id', $ownerUserId)
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            return response()->json([
                'ownerUserId' => $ownerUserId,
                'notifications' => $rows->map(fn ($row) => $this->toResponseItem($row))->values(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage() ?: 'failed'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $this->ensureNotificationsTable();

            $wantsEmailDelivery =
                (bool) $request->boolean('sendEmail') ||
                (bool) $request->boolean('emailNotification') ||
                strtolower(trim((string) $request->input('channel', $request->input('deliveryChannel', '')))) === 'email';

            if ($wantsEmailDelivery) {
                return response()->json(['error' => 'Email delivery is disabled for notifications. In-app only.'], 400);
            }

            $ownerUserId = $this->resolveOwnerUserId($request, (string) $request->input('ownerUserId', ''));
            if (!$ownerUserId) {
                return response()->json(['error' => 'ownerUserId required'], 400);
            }

            $title = trim((string) $request->input('title', ''));
            $message = trim((string) $request->input('message', ''));
            $type = $this->normalizeType($request->input('type'));

            if ($title === '' || $message === '') {
                return response()->json(['error' => 'title and message required'], 400);
            }

            $id = (string) Str::uuid();

            DB::table('in_app_notifications')->insert([
                'id' => $id,
                'user_id' => $ownerUserId,
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'read_at' => null,
                'metadata' => json_encode(['delivery' => 'in_app_only']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('in_app_notifications')
                ->select(['id', 'user_id', 'title', 'message', 'type', 'read_at', 'created_at'])
                ->where('id', $id)
                ->first();

            return response()->json([
                'success' => true,
                'notification' => $row ? $this->toResponseItem($row) : null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage() ?: 'failed'], 500);
        }
    }

    public function update(Request $request)
    {
        try {
            $this->ensureNotificationsTable();

            $ownerUserId = $this->resolveOwnerUserId($request, (string) $request->input('ownerUserId', ''));
            if (!$ownerUserId) {
                return response()->json(['error' => 'ownerUserId required'], 400);
            }

            $markAll = (bool) $request->boolean('markAll');
            $id = trim((string) $request->input('id', ''));

            if ($markAll) {
                DB::table('in_app_notifications')
                    ->where('user_id', $ownerUserId)
                    ->update([
                        'read_at' => DB::raw('COALESCE(read_at, NOW())'),
                        'updated_at' => now(),
                    ]);

                return response()->json(['success' => true, 'updated' => 'all']);
            }

            if ($id === '') {
                return response()->json(['error' => 'id required'], 400);
            }

            DB::table('in_app_notifications')
                ->where('id', $id)
                ->where('user_id', $ownerUserId)
                ->update([
                    'read_at' => DB::raw('COALESCE(read_at, NOW())'),
                    'updated_at' => now(),
                ]);

            return response()->json(['success' => true, 'updated' => $id]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage() ?: 'failed'], 500);
        }
    }

    public function destroy(Request $request)
    {
        try {
            $this->ensureNotificationsTable();

            $ownerUserId = $this->resolveOwnerUserId($request, (string) $request->query('ownerUserId', ''));
            if (!$ownerUserId) {
                return response()->json(['error' => 'ownerUserId required'], 400);
            }

            DB::table('in_app_notifications')->where('user_id', $ownerUserId)->delete();

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage() ?: 'failed'], 500);
        }
    }
}
