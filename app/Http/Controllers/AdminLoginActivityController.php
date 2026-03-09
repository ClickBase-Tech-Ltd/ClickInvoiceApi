<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class AdminLoginActivityController extends Controller
{
    private const COUNTRY_CODE_MAP = [
        'NG' => 'Nigeria',
        'US' => 'United States',
        'GB' => 'United Kingdom',
        'CA' => 'Canada',
        'GH' => 'Ghana',
        'KE' => 'Kenya',
        'ZA' => 'South Africa',
        'IN' => 'India',
        'AE' => 'United Arab Emirates',
    ];

    private function ensureTable(): void
    {
        DB::statement(
            "CREATE TABLE IF NOT EXISTS user_login_activity (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NULL,
                email VARCHAR(255) NULL,
                role VARCHAR(64) NULL,
                subscription_status VARCHAR(64) NULL,
                ip_address VARCHAR(64) NULL,
                country_code VARCHAR(8) NULL,
                country_name VARCHAR(128) NULL,
                device_type VARCHAR(32) NULL,
                device_os VARCHAR(64) NULL,
                device_browser VARCHAR(64) NULL,
                user_agent TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_login_activity_created (created_at),
                KEY idx_login_activity_user_created (user_id, created_at),
                KEY idx_login_activity_email_created (email, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function normalizeRole($role): string
    {
        return strtoupper(trim((string) ($role ?? '')));
    }

    private function isAdminRole($role): bool
    {
        $normalized = $this->normalizeRole($role);
        return in_array($normalized, ['ADMIN', 'SUPER_ADMIN'], true);
    }

    private function pickClientIp(Request $request): ?string
    {
        $forwarded = $request->header('x-forwarded-for');
        if ($forwarded) {
            $parts = explode(',', $forwarded);
            $first = trim((string) ($parts[0] ?? ''));
            if ($first !== '') {
                return $first;
            }
        }

        $ip = trim((string) (
            $request->header('x-real-ip')
            ?? $request->header('cf-connecting-ip')
            ?? $request->header('x-client-ip')
            ?? ''
        ));

        if ($ip !== '') {
            return $ip;
        }

        $fallback = trim((string) $request->ip());
        return $fallback !== '' ? $fallback : null;
    }

    private function maskIp(?string $ip): string
    {
        if (!$ip) {
            if (app()->environment('local')) {
                return 'Local network';
            }
            return 'Unknown';
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return 'Local network';
        }

        if (str_contains($ip, ':')) {
            $chunks = array_values(array_filter(explode(':', $ip)));
            if (count($chunks) <= 2) {
                return '****:****';
            }
            return implode(':', array_slice($chunks, 0, 2)) . ':****:****';
        }

        $chunks = explode('.', $ip);
        if (count($chunks) !== 4) {
            return '***.***.***.***';
        }

        return $chunks[0] . '.' . $chunks[1] . '.***.***';
    }

    private function isLocalIp(?string $ip): bool
    {
        $value = trim((string) ($ip ?? ''));
        if ($value === '') {
            return false;
        }

        return !filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private function parseDevice(string $userAgent): array
    {
        $ua = strtolower($userAgent);

        $isMobile = str_contains($ua, 'mobile') || str_contains($ua, 'iphone') || str_contains($ua, 'android');
        $isTablet = str_contains($ua, 'ipad') || str_contains($ua, 'tablet');
        $deviceType = $isTablet ? 'Tablet' : ($isMobile ? 'Mobile' : 'Desktop');

        $deviceOs = 'Unknown';
        if (str_contains($ua, 'windows')) {
            $deviceOs = 'Windows';
        } elseif (str_contains($ua, 'mac os') || str_contains($ua, 'macintosh')) {
            $deviceOs = 'macOS';
        } elseif (str_contains($ua, 'android')) {
            $deviceOs = 'Android';
        } elseif (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ios')) {
            $deviceOs = 'iOS';
        } elseif (str_contains($ua, 'linux')) {
            $deviceOs = 'Linux';
        }

        $deviceBrowser = 'Unknown';
        if (str_contains($ua, 'edg/')) {
            $deviceBrowser = 'Edge';
        } elseif (str_contains($ua, 'chrome/') && !str_contains($ua, 'edg/')) {
            $deviceBrowser = 'Chrome';
        } elseif (str_contains($ua, 'safari/') && !str_contains($ua, 'chrome/')) {
            $deviceBrowser = 'Safari';
        } elseif (str_contains($ua, 'firefox/')) {
            $deviceBrowser = 'Firefox';
        }

        return [$deviceType, $deviceOs, $deviceBrowser];
    }

    private function countryNameFromCode(?string $code): string
    {
        $normalized = strtoupper(trim((string) ($code ?? '')));
        if ($normalized === '' || $normalized === 'UNKNOWN' || $normalized === 'XX') {
            return 'Unknown';
        }

        return self::COUNTRY_CODE_MAP[$normalized] ?? $normalized;
    }

    private function lookupCountryByIp(?string $ip): array
    {
        $value = trim((string) ($ip ?? ''));
        if ($value === '') {
            return ['countryCode' => 'Unknown', 'countryName' => 'Unknown'];
        }

        if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['countryCode' => 'Unknown', 'countryName' => 'Unknown'];
        }

        try {
            $response = Http::timeout(2)
                ->acceptJson()
                ->get("http://ip-api.com/json/{$value}?fields=status,country,countryCode");

            if (!$response->successful()) {
                return ['countryCode' => 'Unknown', 'countryName' => 'Unknown'];
            }

            $payload = $response->json();
            if (!is_array($payload) || ($payload['status'] ?? '') !== 'success') {
                return ['countryCode' => 'Unknown', 'countryName' => 'Unknown'];
            }

            $countryCode = strtoupper(trim((string) ($payload['countryCode'] ?? '')));
            $countryName = trim((string) ($payload['country'] ?? ''));

            if ($countryCode === '' || $countryCode === 'XX') {
                return ['countryCode' => 'Unknown', 'countryName' => 'Unknown'];
            }

            return [
                'countryCode' => $countryCode,
                'countryName' => $countryName !== '' ? $countryName : $this->countryNameFromCode($countryCode),
            ];
        } catch (\Throwable $e) {
            return ['countryCode' => 'Unknown', 'countryName' => 'Unknown'];
        }
    }

    private function toResponseItem(object $row): array
    {
        $countryCode = (string) ($row->country_code ?? 'Unknown');
        $storedCountry = trim((string) ($row->country_name ?? ''));
        $country = ($storedCountry === '' || strcasecmp($storedCountry, 'Unknown') === 0)
            ? $this->countryNameFromCode($countryCode)
            : $storedCountry;
        $ipAddress = $row->ip_address ? (string) $row->ip_address : null;
        $isLocalIp = $this->isLocalIp($ipAddress);

        if ($isLocalIp && ($country === 'Unknown' || $country === '')) {
            $country = 'Local';
            $countryCode = 'LOCAL';
        }

        if (app()->environment('local') && ($country === 'Unknown' || $country === '') && $ipAddress === null) {
            $country = 'Local';
            $countryCode = 'LOCAL';
        }

        return [
            'id' => (int) $row->id,
            'userId' => $row->user_id === null ? null : (int) $row->user_id,
            'userName' => trim((string) ($row->user_name ?? '')),
            'email' => (string) ($row->email ?? 'Unknown'),
            'role' => (string) ($row->role ?? 'USER'),
            'subscriptionStatus' => (string) ($row->subscription_status ?? 'Unknown'),
            'country' => $country,
            'countryCode' => $countryCode,
            'deviceType' => (string) ($row->device_type ?? 'Unknown'),
            'device' => (string) ($row->device_browser ?? 'Unknown') . ' on ' . (string) ($row->device_os ?? 'Unknown'),
            'maskedIp' => $this->maskIp($ipAddress),
            'createdAt' => $row->created_at,
        ];
    }

    private function resolveUserId(?int $requestedUserId, ?string $email): ?int
    {
        if ($requestedUserId !== null && $requestedUserId > 0) {
            $exists = DB::table('users')
                ->where('id', $requestedUserId)
                ->exists();

            if ($exists) {
                return $requestedUserId;
            }
        }

        if ($email !== null && $email !== '') {
            $resolved = DB::table('users')
                ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                ->value('id');

            if ($resolved !== null) {
                return (int) $resolved;
            }
        }

        $authUserId = auth()->id();
        if ($authUserId !== null) {
            return (int) $authUserId;
        }

        return null;
    }

    public function store(Request $request)
    {
        try {
            $this->ensureTable();

            $userIdRaw = $request->input('userId');
            $userId = ($userIdRaw === null || trim((string) $userIdRaw) === '') ? null : (int) $userIdRaw;
            $email = strtolower(trim((string) $request->input('email', '')));
            $email = $email !== '' ? $email : null;
            $role = strtoupper(trim((string) $request->input('role', 'USER')));
            $subscriptionStatus = trim((string) $request->input('subscriptionStatus', 'Unknown'));

            $resolvedUserId = $this->resolveUserId($userId, $email);

            if ($email === null && $resolvedUserId === null) {
                return response()->json(['error' => 'email or userId is required'], 400);
            }

            $userAgent = trim((string) $request->userAgent());
            $ipAddress = $this->pickClientIp($request);

            $headerCountry = strtoupper(trim((string) ($request->header('x-vercel-ip-country') ?? $request->header('cf-ipcountry') ?? '')));
            $countryCode = ($headerCountry !== '' && $headerCountry !== 'XX') ? $headerCountry : 'Unknown';
            $countryName = $this->countryNameFromCode($countryCode);

            if ($countryCode === 'Unknown') {
                $resolved = $this->lookupCountryByIp($ipAddress);
                $countryCode = $resolved['countryCode'];
                $countryName = $resolved['countryName'];
            }

            [$deviceType, $deviceOs, $deviceBrowser] = $this->parseDevice($userAgent);

            DB::table('user_login_activity')->insert([
                'user_id' => $resolvedUserId,
                'email' => $email,
                'role' => $role !== '' ? $role : null,
                'subscription_status' => $subscriptionStatus !== '' ? $subscriptionStatus : null,
                'ip_address' => $ipAddress,
                'country_code' => $countryCode,
                'country_name' => $countryName,
                'device_type' => $deviceType,
                'device_os' => $deviceOs,
                'device_browser' => $deviceBrowser,
                'user_agent' => $userAgent !== '' ? $userAgent : null,
                'created_at' => now(),
            ]);

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage() ?: 'failed'], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $this->ensureTable();

            if (!$this->isAdminRole($request->header('x-user-role'))) {
                return response()->json(['error' => 'forbidden'], 403);
            }

            $limitRaw = (int) $request->query('limit', 50);
            $limit = max(1, min(500, $limitRaw));

            $rows = DB::table('user_login_activity')
                ->leftJoin('users', 'users.id', '=', 'user_login_activity.user_id')
                ->select([
                    'user_login_activity.id',
                    'user_login_activity.user_id',
                    'user_login_activity.email',
                    'user_login_activity.role',
                    'user_login_activity.subscription_status',
                    'user_login_activity.ip_address',
                    'user_login_activity.country_code',
                    'user_login_activity.country_name',
                    'user_login_activity.device_type',
                    'user_login_activity.device_os',
                    'user_login_activity.device_browser',
                    'user_login_activity.created_at',
                    DB::raw("TRIM(CONCAT(COALESCE(users.firstName, ''), ' ', COALESCE(users.lastName, ''))) as user_name"),
                ])
                ->orderByDesc('user_login_activity.created_at')
                ->limit($limit)
                ->get();

            return response()->json([
                'loginEvents' => $rows->map(fn ($row) => $this->toResponseItem($row))->values(),
                'total' => $rows->count(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage() ?: 'failed'], 500);
        }
    }
}
