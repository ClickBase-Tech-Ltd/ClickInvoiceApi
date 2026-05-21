<?php

namespace App\Http\Controllers;

use App\Services\WebPushService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PushSubscriptionController extends Controller
{
    public function __construct(private readonly WebPushService $webPush)
    {
    }

    public function vapidPublicKey()
    {
        $key = $this->webPush->publicKey();
        if (!$key) {
            return response()->json(['error' => 'Web push is not configured on the server'], 503);
        }

        return response()->json(['publicKey' => $key]);
    }

    public function subscribe(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $endpoint = trim((string) $request->input('endpoint', ''));
        $p256dh = trim((string) data_get($request->input('keys', []), 'p256dh', $request->input('p256dh', '')));
        $auth = trim((string) data_get($request->input('keys', []), 'auth', $request->input('auth', '')));

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            return response()->json(['error' => 'endpoint and keys are required'], 400);
        }

        $this->webPush->ensureSubscriptionsTable();

        $now = now();
        $existing = DB::table('push_subscriptions')->where('endpoint', $endpoint)->first();

        $row = [
            'user_id' => $user->id,
            'p256dh' => $p256dh,
            'auth' => $auth,
            'user_agent' => Str::limit((string) $request->userAgent(), 500),
            'platform' => Str::limit((string) $request->input('platform', ''), 64),
            'announcements_enabled' => $request->boolean('announcements', true) ? 1 : 0,
            'billing_enabled' => $request->boolean('billing', true) ? 1 : 0,
            'updated_at' => $now,
        ];

        if ($existing) {
            DB::table('push_subscriptions')->where('id', $existing->id)->update($row);
        } else {
            DB::table('push_subscriptions')->insert(array_merge($row, [
                'id' => (string) Str::uuid(),
                'endpoint' => $endpoint,
                'created_at' => $now,
            ]));
        }

        return response()->json(['success' => true]);
    }

    public function unsubscribe(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $this->webPush->ensureSubscriptionsTable();

        $endpoint = trim((string) $request->input('endpoint', ''));

        $query = DB::table('push_subscriptions')->where('user_id', $user->id);
        if ($endpoint !== '') {
            $query->where('endpoint', $endpoint);
        }
        $query->delete();

        return response()->json(['success' => true]);
    }

    public function updatePreferences(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $this->webPush->ensureSubscriptionsTable();

        $updates = ['updated_at' => now()];
        if ($request->has('announcements')) {
            $updates['announcements_enabled'] = $request->boolean('announcements') ? 1 : 0;
        }
        if ($request->has('billing')) {
            $updates['billing_enabled'] = $request->boolean('billing') ? 1 : 0;
        }

        DB::table('push_subscriptions')
            ->where('user_id', $user->id)
            ->update($updates);

        return response()->json(['success' => true]);
    }
}
