<?php

namespace App\Http\Controllers;

use App\Services\WebPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    private function userIdExists(string $userId): bool
    {
        $id = trim($userId);
        if ($id === '' || !ctype_digit($id)) {
            return false;
        }

        return DB::table('users')->where('id', (int) $id)->exists();
    }

    /**
     * Resolve notification recipient. When $explicitOwnerId is sent in the request body,
     * do not fall back to x-user-email (prevents admin broadcasts landing on the wrong account).
     */
    private function resolveOwnerUserId(Request $request, ?string $directOwner = null, bool $strictExplicit = false): ?string
    {
        $direct = trim((string) ($directOwner ?? ''));
        if ($direct !== '') {
            if ($this->userIdExists($direct)) {
                return $direct;
            }
            if ($strictExplicit) {
                return null;
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

            if ($row && $request->boolean('sendPush', true)) {
                try {
                    $appUrl = rtrim((string) config('services.webpush.app_url', 'https://app.clickinvoice.app'), '/');
                    app(WebPushService::class)->sendToUser((int) $ownerUserId, [
                        'title' => $title,
                        'body' => Str::limit($message, 200),
                        'url' => $appUrl . '/dashboard/',
                        'tag' => 'in-app-' . $id,
                        'notificationId' => $id,
                    ], 'announcement');
                } catch (\Throwable $e) {
                    Log::channel('daily')->warning('In-app push delivery failed', [
                        'userId' => $ownerUserId,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'notification' => $row ? $this->toResponseItem($row) : null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage() ?: 'failed'], 500);
        }
    }

    private function isAdminUser($user): bool
    {
        $role = strtoupper(trim((string) ($user->user_role->roleName ?? '')));
        if ($role === 'SUPERADMIN') {
            $role = 'SUPER_ADMIN';
        }

        return in_array($role, ['ADMIN', 'SUPER_ADMIN'], true);
    }

    private function insertNotificationForUser(
        int $userId,
        string $title,
        string $message,
        string $type = 'info',
        bool $sendPush = true
    ): array {
        $id = (string) Str::uuid();

        DB::table('in_app_notifications')->insert([
            'id' => $id,
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'type' => $this->normalizeType($type),
            'read_at' => null,
            'metadata' => json_encode(['delivery' => 'admin_broadcast']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('in_app_notifications')
            ->select(['id', 'user_id', 'title', 'message', 'type', 'read_at', 'created_at'])
            ->where('id', $id)
            ->first();

        if ($row && $sendPush) {
            try {
                $appUrl = rtrim((string) config('services.webpush.app_url', 'https://app.clickinvoice.app'), '/');
                app(WebPushService::class)->sendToUser($userId, [
                    'title' => $title,
                    'body' => Str::limit($message, 200),
                    'url' => $appUrl . '/dashboard/',
                    'tag' => 'in-app-' . $id,
                    'notificationId' => $id,
                ], 'announcement');
            } catch (\Throwable $e) {
                Log::channel('daily')->warning('Admin broadcast push failed', [
                    'userId' => $userId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $row ? $this->toResponseItem($row) : ['id' => $id];
    }

    /**
     * Admin: create the same in-app notification for many users (JWT required).
     */
    public function adminBroadcast(Request $request)
    {
        try {
            $this->ensureNotificationsTable();

            $actor = auth()->user();
            if (!$actor || !$this->isAdminUser($actor)) {
                return response()->json(['error' => 'Forbidden'], 403);
            }

            $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
                'userIds' => 'required|array|min:1',
                'userIds.*' => 'integer|exists:users,id',
                'title' => 'required|string|max:255',
                'message' => 'required|string',
                'type' => 'nullable|string|max:16',
                'sendPush' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()->first()], 422);
            }

            $userIds = array_values(array_unique(array_map('intval', $request->input('userIds', []))));
            $title = trim((string) $request->input('title', ''));
            $message = trim((string) $request->input('message', ''));
            $type = (string) $request->input('type', 'info');
            $sendPush = $request->boolean('sendPush', true);

            $sent = 0;
            $failed = [];

            foreach ($userIds as $userId) {
                try {
                    $this->insertNotificationForUser($userId, $title, $message, $type, $sendPush);
                    $sent++;
                } catch (\Throwable $e) {
                    $failed[] = [
                        'userId' => $userId,
                        'error' => $e->getMessage() ?: 'failed',
                    ];
                }
            }

            return response()->json([
                'success' => $sent > 0,
                'sent' => $sent,
                'failed' => $failed,
                'total' => count($userIds),
            ], $sent > 0 ? 200 : 500);
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
