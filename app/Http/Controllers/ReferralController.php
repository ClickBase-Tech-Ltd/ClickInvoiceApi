<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ReferralController extends Controller
{
    private const DEFAULT_REWARD_AMOUNT = 100;
    private const DEFAULT_REWARD_CURRENCY = 'USD';

    private const FALLBACK_RATES_TO_USD = [
        'USD' => 1,
        'NGN' => 0.000625,
        'GHS' => 0.064,
        'UGX' => 0.00027,
        'KES' => 0.0077,
        'KSH' => 0.0077,
        'TZS' => 0.00039,
        'RWF' => 0.00071,
        'ETB' => 0.0078,
        'ZAR' => 0.053,
        'ZMW' => 0.037,
        'MWK' => 0.00058,
        'XOF' => 0.00165,
        'XAF' => 0.00165,
        'GBP' => 1.27,
        'EUR' => 1.09,
        'LRD' => 0.0052,
    ];

    private function normalizeCurrency(?string $raw): string
    {
        return substr(preg_replace('/[^A-Z]/', '', strtoupper(trim((string) ($raw ?? '')))) ?? '', 0, 8);
    }

    private function decodeStoredValue($raw)
    {
        $text = trim((string) ($raw ?? ''));
        if ($text === '') {
            return '';
        }

        try {
            return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return $text;
        }
    }

    private function ensureSettingsTable(): void
    {
        DB::statement(
            "CREATE TABLE IF NOT EXISTS referral_settings (
                setting_key VARCHAR(64) NOT NULL,
                setting_value VARCHAR(255) NOT NULL,
                updated_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function ensureWithdrawalTable(): void
    {
        DB::statement(
            "CREATE TABLE IF NOT EXISTS referral_withdrawals (
                id CHAR(36) NOT NULL,
                referrer_user_id BIGINT(20) UNSIGNED NOT NULL,
                currency VARCHAR(8) NOT NULL,
                amount DECIMAL(14,2) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'requested',
                requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                processed_at DATETIME NULL,
                note TEXT NULL,
                metadata LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_referral_withdrawals_referrer (referrer_user_id),
                KEY idx_referral_withdrawals_currency_status (currency, status),
                KEY idx_referral_withdrawals_status_requested (status, requested_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function getRewardSettings(): array
    {
        $this->ensureSettingsTable();

        DB::statement(
            "INSERT INTO referral_settings (setting_key, setting_value, updated_at)
             VALUES ('reward_currency', ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()",
            [json_encode(self::DEFAULT_REWARD_CURRENCY)]
        );

        $rows = DB::table('referral_settings')
            ->select(['setting_key', 'setting_value', 'updated_at'])
            ->whereIn('setting_key', ['reward_amount', 'reward_currency'])
            ->get();

        $map = [];
        $updatedAt = null;
        foreach ($rows as $row) {
            $map[(string) $row->setting_key] = $this->decodeStoredValue($row->setting_value);
            if ($updatedAt === null && !empty($row->updated_at)) {
                $updatedAt = $row->updated_at;
            }
        }

        $amountParsed = (float) ($map['reward_amount'] ?? self::DEFAULT_REWARD_AMOUNT);
        $amount = is_finite($amountParsed) && $amountParsed > 0 ? $amountParsed : self::DEFAULT_REWARD_AMOUNT;

        $parsedCurrency = $this->normalizeCurrency((string) ($map['reward_currency'] ?? self::DEFAULT_REWARD_CURRENCY));
        $currency = $parsedCurrency !== '' ? $parsedCurrency : self::DEFAULT_REWARD_CURRENCY;

        return [
            'amount' => $amount,
            'currency' => $currency,
            'updatedAt' => $updatedAt,
        ];
    }

    private function fetchWithdrawalBalances(string $ownerUserId): array
    {
        $issuedByCurrency = DB::table('referral_rewards')
            ->selectRaw("UPPER(COALESCE(NULLIF(reward_currency, ''), 'USD')) as currency, COALESCE(SUM(reward_amount),0) as amount")
            ->where('referrer_user_id', $ownerUserId)
            ->where('status', 'issued')
            ->groupBy('currency')
            ->get();

        $withdrawnByCurrency = DB::table('referral_withdrawals')
            ->selectRaw("UPPER(COALESCE(NULLIF(currency, ''), 'USD')) as currency, COALESCE(SUM(amount),0) as amount")
            ->where('referrer_user_id', $ownerUserId)
            ->whereIn('status', ['requested', 'processing', 'approved', 'paid'])
            ->groupBy('currency')
            ->get();

        $issuedMap = [];
        foreach ($issuedByCurrency as $row) {
            $issuedMap[(string) $row->currency] = (float) ($row->amount ?? 0);
        }

        $withdrawnMap = [];
        foreach ($withdrawnByCurrency as $row) {
            $withdrawnMap[(string) $row->currency] = (float) ($row->amount ?? 0);
        }

        $currencies = array_values(array_unique(array_merge(array_keys($issuedMap), array_keys($withdrawnMap))));
        sort($currencies);

        $balances = [];
        foreach ($currencies as $currency) {
            $issuedAmount = $issuedMap[$currency] ?? 0;
            $withdrawnAmount = $withdrawnMap[$currency] ?? 0;
            $availableAmount = max(0, $issuedAmount - $withdrawnAmount);

            $balances[] = [
                'currency' => $currency,
                'issuedAmount' => $issuedAmount,
                'withdrawnAmount' => $withdrawnAmount,
                'availableAmount' => $availableAmount,
            ];
        }

        return $balances;
    }

    private function resolveReferrerRewardCurrency(string $referrerUserId): string
    {
        $currencyCode = DB::table('tenants as t')
            ->leftJoin('currencies as c', 'c.currencyId', '=', 't.currency')
            ->where('t.ownerId', $referrerUserId)
            ->where('t.isDefault', 1)
            ->value('c.currencyCode');

        $fallback = strtoupper((string) env('REFERRAL_SIGNUP_REWARD_CURRENCY', 'NGN'));
        $resolved = strtoupper(trim((string) ($currencyCode ?? '')));

        return $resolved !== '' ? $resolved : $fallback;
    }

    private function resolveRewardAmountForCurrency(string $currencyCode): float
    {
        $normalizedCurrency = strtoupper(trim($currencyCode));
        $defaultAmount = (float) env('REFERRAL_SIGNUP_REWARD_NGN', 1000);
        if ($normalizedCurrency === '') {
            return $defaultAmount;
        }

        $currencyAmount = env('REFERRAL_SIGNUP_REWARD_' . $normalizedCurrency);
        if ($currencyAmount === null || $currencyAmount === '') {
            return $defaultAmount;
        }

        return (float) $currencyAmount;
    }

    private function issueSignupRewardIfEligible(string $referredUserId, object $refCode): void
    {
        $referredUser = DB::table('users')
            ->select(['id', 'status', 'email_verified_at'])
            ->where('id', $referredUserId)
            ->first();

        if (!$referredUser) {
            return;
        }

        $isActive = strtolower((string) ($referredUser->status ?? '')) === 'active';
        $isVerified = !empty($referredUser->email_verified_at);

        if (!$isActive || !$isVerified) {
            return;
        }

        $alreadyIssued = DB::table('referral_rewards')
            ->where('referrer_user_id', (string) $refCode->owner_user_id)
            ->where('referred_user_id', (string) $referredUserId)
            ->where('status', 'issued')
            ->exists();

        if ($alreadyIssued) {
            return;
        }

        $rewardCurrency = $this->resolveReferrerRewardCurrency((string) $refCode->owner_user_id);
        $rewardAmount = $this->resolveRewardAmountForCurrency($rewardCurrency);

        DB::table('referral_rewards')->insert([
            'id' => (string) Str::uuid(),
            'referral_code_id' => $refCode->id,
            'referrer_user_id' => (string) $refCode->owner_user_id,
            'referred_user_id' => (string) $referredUserId,
            'reward_type' => 'credit',
            'reward_amount' => $rewardAmount,
            'reward_currency' => $rewardCurrency,
            'status' => 'issued',
            'reason' => 'issued on active + verified signup',
            'metadata' => json_encode([
                'trigger' => 'signup_verified',
            ]),
            'created_at' => now(),
            'issued_at' => now(),
            'reversed_at' => null,
        ]);
    }

    private function pickOwnerId(Request $request, bool $allowAuthUser = true): ?string
    {
        $emailHint = trim(strtolower((string) $request->header('x-user-email', '')));
        if ($emailHint !== '') {
            $byEmail = DB::table('users')
                ->whereRaw('TRIM(LOWER(email)) = ?', [$emailHint])
                ->value('id');
            if ($byEmail !== null) {
                return (string) $byEmail;
            }
        }

        $candidate = $request->input('ownerUserId', $request->query('ownerUserId'));
        if ($candidate !== null && trim((string) $candidate) !== '') {
            return (string) $candidate;
        }

        if ($allowAuthUser && auth()->check()) {
            return (string) auth()->id();
        }

        return null;
    }

    private function normalizePreferredCode(?string $value): string
    {
        return substr(preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $value)) ?? '', 0, 16);
    }

    private function randomCode(): string
    {
        return strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 10));
    }

    public function generate(Request $request)
    {
        $ownerUserId = $this->pickOwnerId($request, true);
        if (!$ownerUserId) {
            return response()->json(['error' => 'ownerUserId required'], 400);
        }

        $ownerExists = DB::table('users')->where('id', $ownerUserId)->exists();
        if (!$ownerExists) {
            return response()->json(['error' => 'Owner user does not exist'], 404);
        }

        $existing = DB::table('referral_codes')
            ->where('owner_user_id', $ownerUserId)
            ->orderBy('created_at')
            ->first();

        if ($existing) {
            DB::table('users')->where('id', $ownerUserId)->update(['own_referral_code' => $existing->code]);

            return response()->json([
                'code' => $existing->code,
                'ownerUserId' => (string) $ownerUserId,
                'saved' => true,
                'shareUrl' => rtrim((string) env('APP_URL', ''), '/') . '/signup?r=' . $existing->code,
            ]);
        }

        $preferred = $this->normalizePreferredCode($request->input('preferredCode'));
        if ($request->filled('preferredCode') && strlen($preferred) < 4) {
            return response()->json(['error' => 'preferredCode must contain at least 4 alphanumeric chars'], 400);
        }
        if ($preferred !== '' && !preg_match('/^[A-Z0-9]{4,16}$/', $preferred)) {
            return response()->json(['error' => 'preferredCode must be 4-16 alphanumeric chars'], 400);
        }

        $code = $preferred;
        if ($code !== '') {
            $codeExists = DB::table('referral_codes')->where('code', $code)->exists();
            if ($codeExists) {
                return response()->json(['error' => 'preferredCode already in use'], 409);
            }
        } else {
            for ($i = 0; $i < 8; $i++) {
                $candidate = $this->randomCode();
                $inUse = DB::table('referral_codes')->where('code', $candidate)->exists();
                if (!$inUse) {
                    $code = $candidate;
                    break;
                }
            }
            if ($code === '') {
                return response()->json(['error' => 'Failed to generate unique code'], 500);
            }
        }

        $campaign = $request->input('campaign');
        DB::table('referral_codes')->insert([
            'id' => (string) Str::uuid(),
            'code' => $code,
            'owner_user_id' => $ownerUserId,
            'campaign' => $campaign,
            'max_uses' => null,
            'uses_count' => 0,
            'metadata' => json_encode(new \stdClass()),
            'created_at' => now(),
            'expires_at' => null,
        ]);

        DB::table('users')->where('id', $ownerUserId)->update(['own_referral_code' => $code]);

        return response()->json([
            'code' => $code,
            'ownerUserId' => (string) $ownerUserId,
            'saved' => true,
            'shareUrl' => rtrim((string) env('APP_URL', ''), '/') . '/signup?r=' . $code,
        ]);
    }

    public function me(Request $request)
    {
        $ownerUserId = $this->pickOwnerId($request, true);
        if (!$ownerUserId) {
            return response()->json(['error' => 'ownerUserId required'], 400);
        }

        $ownReferralCode = DB::table('users')->where('id', $ownerUserId)->value('own_referral_code');

        $codes = DB::table('referral_codes')
            ->select(['id', 'code', 'owner_user_id', 'campaign', 'uses_count', 'created_at'])
            ->where('owner_user_id', $ownerUserId)
            ->orderBy('created_at')
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (string) $row->id,
                    'code' => (string) $row->code,
                    'ownerUserId' => (string) $row->owner_user_id,
                    'campaign' => $row->campaign,
                    'usesCount' => (int) ($row->uses_count ?? 0),
                    'createdAt' => (string) $row->created_at,
                ];
            })
            ->values()
            ->all();

        if (!$ownReferralCode && count($codes) > 0) {
            $ownReferralCode = $codes[0]['code'];
            DB::table('users')
                ->where('id', $ownerUserId)
                ->whereNull('own_referral_code')
                ->update(['own_referral_code' => $ownReferralCode]);
        }

        $codeList = array_map(fn ($c) => $c['code'], $codes);
        if ($ownReferralCode && !in_array($ownReferralCode, $codeList, true)) {
            array_unshift($codes, [
                'id' => 'user_' . $ownerUserId . '_own_ref_code',
                'code' => (string) $ownReferralCode,
                'ownerUserId' => (string) $ownerUserId,
                'campaign' => null,
                'usesCount' => 0,
                'createdAt' => now()->toISOString(),
            ]);
            $codeList[] = $ownReferralCode;
        }

        $eventsQuery = DB::table('referral_events as e')
            ->leftJoin('referral_codes as c', 'c.id', '=', 'e.referral_code_id')
            ->selectRaw('e.event_type as eventType, COALESCE(e.referral_code, c.code) as referralCode, e.referred_user_id as referredUserId, e.created_at as createdAt')
            ->where(function ($q) use ($ownerUserId, $codeList, $ownReferralCode) {
                $q->where('c.owner_user_id', $ownerUserId);
                if (!empty($codeList)) {
                    $q->orWhereIn('e.referral_code', $codeList);
                }
                if ($ownReferralCode) {
                    $q->orWhere('e.referral_code', $ownReferralCode);
                }
            })
            ->orderBy('e.created_at')
            ->get();

        $events = $eventsQuery->map(function ($event) {
            return [
                'eventType' => (string) ($event->eventType ?? ''),
                'referralCode' => $event->referralCode ? (string) $event->referralCode : null,
                'referredUserId' => $event->referredUserId ? (string) $event->referredUserId : null,
                'createdAt' => (string) $event->createdAt,
            ];
        })->values()->all();

        $rewardsIssued = (float) (DB::table('referral_rewards')
            ->where('referrer_user_id', $ownerUserId)
            ->where('status', 'issued')
            ->sum('reward_amount') ?? 0);

        $rewardsIssuedByCurrency = DB::table('referral_rewards')
            ->selectRaw('UPPER(COALESCE(NULLIF(reward_currency, ""), ?)) as currency, SUM(reward_amount) as amount', [strtoupper((string) env('REFERRAL_SIGNUP_REWARD_CURRENCY', 'NGN'))])
            ->where('referrer_user_id', $ownerUserId)
            ->where('status', 'issued')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(function ($row) {
                return [
                    'currency' => (string) $row->currency,
                    'amount' => (float) ($row->amount ?? 0),
                ];
            })
            ->values()
            ->all();

        $stats = [
            'totalCodes' => count($codes),
            'totalClicks' => count(array_filter($events, fn ($e) => $e['eventType'] === 'click')),
            'totalSignups' => count(array_filter($events, fn ($e) => $e['eventType'] === 'signup')),
            'totalConversions' => count(array_filter($events, fn ($e) => $e['eventType'] === 'conversion')),
            'rewardsIssued' => $rewardsIssued,
            'rewardsIssuedByCurrency' => $rewardsIssuedByCurrency,
        ];

        return response()->json([
            'ownReferralCode' => $ownReferralCode ? (string) $ownReferralCode : null,
            'codes' => $codes,
            'events' => $events,
            'stats' => $stats,
        ]);
    }

    public function resolve(Request $request)
    {
        $code = strtoupper(trim((string) $request->query('code', '')));
        if ($code === '') {
            return response()->json(['valid' => false], 400);
        }

        $row = DB::table('referral_codes')->where('code', $code)->first();
        if (!$row) {
            return response()->json(['valid' => false]);
        }

        try {
            DB::table('referral_events')->insert([
                'id' => (string) Str::uuid(),
                'referral_code_id' => $row->id,
                'referral_code' => $code,
                'event_type' => 'click',
                'referred_user_id' => null,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'payload' => json_encode(new \stdClass()),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }

        return response()->json([
            'valid' => true,
            'ownerUserId' => (string) $row->owner_user_id,
            'campaign' => $row->campaign,
        ]);
    }

    public function redeem(Request $request)
    {
        $code = strtoupper(trim((string) $request->input('code', '')));
        $referredUserId = $request->input('referredUserId');

        if ($code === '' || $referredUserId === null || trim((string) $referredUserId) === '') {
            return response()->json(['error' => 'code and referredUserId are required'], 400);
        }

        $refCode = DB::table('referral_codes')->where('code', $code)->first();
        if (!$refCode) {
            return response()->json(['error' => 'invalid referral code'], 404);
        }

        if ((string) $refCode->owner_user_id === (string) $referredUserId) {
            return response()->json(['error' => 'self referral not allowed'], 400);
        }

        $alreadyRedeemed = DB::table('referral_events')
            ->where('event_type', 'signup')
            ->where('referred_user_id', (string) $referredUserId)
            ->exists();

        if ($alreadyRedeemed) {
            DB::table('users')
                ->where('id', (string) $referredUserId)
                ->whereNull('referred_by_code')
                ->update([
                    'referred_by_code' => (string) $refCode->code,
                    'referred_by_user_id' => (string) $refCode->owner_user_id,
                    'referral_attributed_at' => now(),
                ]);

            $this->issueSignupRewardIfEligible((string) $referredUserId, $refCode);
            return response()->json(['success' => true, 'message' => 'already redeemed']);
        }

        DB::beginTransaction();
        try {
            DB::table('referral_events')->insert([
                'id' => (string) Str::uuid(),
                'referral_code_id' => $refCode->id,
                'referral_code' => $code,
                'event_type' => 'signup',
                'referred_user_id' => (string) $referredUserId,
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 255),
                'payload' => json_encode(new \stdClass()),
                'created_at' => now(),
            ]);

            DB::table('users')
                ->where('id', (string) $referredUserId)
                ->update([
                    'referred_by_code' => (string) $refCode->code,
                    'referred_by_user_id' => (string) $refCode->owner_user_id,
                    'referral_attributed_at' => now(),
                ]);

            DB::table('referral_codes')->where('id', $refCode->id)->increment('uses_count');

            $this->issueSignupRewardIfEligible((string) $referredUserId, $refCode);

            DB::commit();
            return response()->json(['success' => true, 'rewardStatus' => 'issued_only_when_active_and_verified']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'failed to redeem referral code'], 500);
        }
    }

    public function currentOwner(Request $request)
    {
        try {
            $ownerUserId = $this->pickOwnerId($request, true);
            return response()->json(['ownerUserId' => $ownerUserId ?: null]);
        } catch (\Throwable $e) {
            return response()->json(['ownerUserId' => null], 200);
        }
    }

    public function adminOverview()
    {
        try {
            $total = DB::selectOne(
                "SELECT
                    (SELECT COUNT(*) FROM referral_codes) AS totalCodes,
                    (SELECT COUNT(*) FROM referral_events WHERE event_type = 'click') AS totalClicks,
                    (SELECT COUNT(*) FROM referral_events WHERE event_type = 'signup') AS totalSignups,
                    (SELECT COUNT(*) FROM referral_events WHERE event_type = 'conversion') AS totalConversions,
                    (SELECT COALESCE(SUM(reward_amount), 0) FROM referral_rewards WHERE status = 'issued') AS rewardsIssued"
            );

            $users = DB::select(
                "SELECT
                    u.id AS userId,
                    u.firstName,
                    u.lastName,
                    u.email,
                    u.own_referral_code AS ownReferralCode,
                    (SELECT COUNT(*) FROM referral_codes rc WHERE rc.owner_user_id = u.id) AS createdCodes,
                    (SELECT COALESCE(SUM(rc2.uses_count), 0) FROM referral_codes rc2 WHERE rc2.owner_user_id = u.id) AS totalUses,
                    (SELECT COALESCE(SUM(rr.reward_amount), 0) FROM referral_rewards rr WHERE rr.referrer_user_id = u.id AND rr.status = 'issued') AS rewardsIssued
                FROM users u
                WHERE u.own_referral_code IS NOT NULL
                    OR EXISTS (SELECT 1 FROM referral_codes rc WHERE rc.owner_user_id = u.id)
                ORDER BY createdCodes DESC, totalUses DESC, u.created_at DESC
                LIMIT 500"
            );

            return response()->json([
                'stats' => [
                    'totalCodes' => (int) ($total->totalCodes ?? 0),
                    'totalClicks' => (int) ($total->totalClicks ?? 0),
                    'totalSignups' => (int) ($total->totalSignups ?? 0),
                    'totalConversions' => (int) ($total->totalConversions ?? 0),
                    'rewardsIssued' => (float) ($total->rewardsIssued ?? 0),
                ],
                'users' => collect($users)->map(function ($row) {
                    return [
                        'userId' => (string) $row->userId,
                        'firstName' => $row->firstName,
                        'lastName' => $row->lastName,
                        'email' => $row->email,
                        'ownReferralCode' => $row->ownReferralCode,
                        'createdCodes' => (int) ($row->createdCodes ?? 0),
                        'totalUses' => (int) ($row->totalUses ?? 0),
                        'rewardsIssued' => (float) ($row->rewardsIssued ?? 0),
                    ];
                })->values(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage() ?: 'failed'], 500);
        }
    }

    public function payoutReady(Request $request)
    {
        try {
            $currency = $this->normalizeCurrency((string) $request->query('currency', ''));

            $summary = DB::select(
                "SELECT
                    UPPER(COALESCE(NULLIF(reward_currency, ''), 'USD')) AS currency,
                    COUNT(*) AS rewardsCount,
                    COALESCE(SUM(reward_amount), 0) AS totalAmount
                FROM referral_rewards
                WHERE status = 'issued'
                    AND (? = '' OR UPPER(COALESCE(NULLIF(reward_currency, ''), 'USD')) = ?)
                GROUP BY UPPER(COALESCE(NULLIF(reward_currency, ''), 'USD'))
                ORDER BY currency ASC",
                [$currency, $currency]
            );

            $rows = DB::select(
                "SELECT
                    rr.id,
                    UPPER(COALESCE(NULLIF(rr.reward_currency, ''), 'USD')) AS currency,
                    rr.reward_amount AS amount,
                    rr.referrer_user_id,
                    ref.email AS referrer_email,
                    rr.referred_user_id,
                    referred.email AS referred_email,
                    rr.created_at,
                    rr.issued_at
                FROM referral_rewards rr
                LEFT JOIN users ref ON ref.id = rr.referrer_user_id
                LEFT JOIN users referred ON referred.id = rr.referred_user_id
                WHERE rr.status = 'issued'
                    AND (? = '' OR UPPER(COALESCE(NULLIF(rr.reward_currency, ''), 'USD')) = ?)
                ORDER BY currency ASC, rr.issued_at DESC, rr.created_at DESC
                LIMIT 200",
                [$currency, $currency]
            );

            return response()->json([
                'currency' => $currency !== '' ? $currency : null,
                'summary' => collect($summary)->map(fn ($item) => [
                    'currency' => (string) $item->currency,
                    'rewardsCount' => (int) ($item->rewardsCount ?? 0),
                    'totalAmount' => (float) ($item->totalAmount ?? 0),
                ])->values(),
                'rows' => collect($rows)->map(fn ($item) => [
                    'id' => (string) $item->id,
                    'currency' => (string) $item->currency,
                    'amount' => (float) ($item->amount ?? 0),
                    'referrerUserId' => $item->referrer_user_id ? (string) $item->referrer_user_id : null,
                    'referrerEmail' => $item->referrer_email,
                    'referredUserId' => $item->referred_user_id ? (string) $item->referred_user_id : null,
                    'referredEmail' => $item->referred_email,
                    'createdAt' => $item->created_at,
                    'issuedAt' => $item->issued_at,
                ])->values(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage() ?: 'failed'], 500);
        }
    }

    public function exchangeRates()
    {
        try {
            $response = Http::timeout(10)->get('https://open.er-api.com/v6/latest/USD');
            if ($response->ok()) {
                $json = $response->json();
                $ratesFromUsd = is_array($json['rates'] ?? null) ? $json['rates'] : null;
                if ($ratesFromUsd) {
                    $rates = ['USD' => 1];
                    foreach ($ratesFromUsd as $currency => $value) {
                        $code = strtoupper(trim((string) $currency));
                        $parsed = (float) $value;
                        if ($code === '' || !is_finite($parsed) || $parsed <= 0) {
                            continue;
                        }
                        $rates[$code] = 1 / $parsed;
                    }

                    return response()->json([
                        'baseCurrency' => 'USD',
                        'rates' => $rates,
                        'source' => 'open.er-api.com',
                        'fetchedAt' => now()->toISOString(),
                    ]);
                }
            }

            return response()->json([
                'baseCurrency' => 'USD',
                'rates' => self::FALLBACK_RATES_TO_USD,
                'source' => 'fallback',
                'fetchedAt' => now()->toISOString(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'baseCurrency' => 'USD',
                'rates' => self::FALLBACK_RATES_TO_USD,
                'source' => 'fallback',
                'fetchedAt' => now()->toISOString(),
            ]);
        }
    }

    public function rewardSettings()
    {
        try {
            return response()->json($this->getRewardSettings());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage() ?: 'failed'], 500);
        }
    }

    public function updateRewardSettings(Request $request)
    {
        try {
            $amount = (float) $request->input('amount', 0);
            if (!is_finite($amount) || $amount <= 0) {
                return response()->json(['error' => 'amount must be greater than 0'], 400);
            }

            $currency = self::DEFAULT_REWARD_CURRENCY;

            $this->ensureSettingsTable();

            DB::statement(
                "INSERT INTO referral_settings (setting_key, setting_value, updated_at)
                VALUES ('reward_amount', ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()",
                [json_encode($amount)]
            );

            DB::statement(
                "INSERT INTO referral_settings (setting_key, setting_value, updated_at)
                VALUES ('reward_currency', ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()",
                [json_encode($currency)]
            );

            return response()->json($this->getRewardSettings());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage() ?: 'failed'], 500);
        }
    }

    public function withdrawals(Request $request)
    {
        try {
            $this->ensureWithdrawalTable();

            $scope = strtolower(trim((string) $request->query('scope', '')));
            $isAdminScope = in_array($scope, ['all', 'admin'], true);

            if ($isAdminScope) {
                $requests = DB::select(
                    "SELECT rw.id, rw.referrer_user_id, u.email AS referrer_email, rw.currency, rw.amount, rw.status, rw.requested_at, rw.processed_at, rw.note
                     FROM referral_withdrawals rw
                     LEFT JOIN users u ON u.id = rw.referrer_user_id
                     ORDER BY rw.requested_at DESC
                     LIMIT 300"
                );

                return response()->json([
                    'requests' => collect($requests)->map(fn ($row) => [
                        'id' => (string) $row->id,
                        'referrerUserId' => (string) $row->referrer_user_id,
                        'referrerEmail' => $row->referrer_email,
                        'currency' => (string) $row->currency,
                        'amount' => (float) ($row->amount ?? 0),
                        'status' => (string) ($row->status ?? ''),
                        'requestedAt' => $row->requested_at,
                        'processedAt' => $row->processed_at,
                        'note' => $row->note,
                    ])->values(),
                ]);
            }

            $ownerUserId = $this->pickOwnerId($request, true);
            if (!$ownerUserId) {
                return response()->json(['error' => 'ownerUserId required'], 400);
            }

            $balances = $this->fetchWithdrawalBalances($ownerUserId);

            $requests = DB::table('referral_withdrawals')
                ->select(['id', 'referrer_user_id', 'currency', 'amount', 'status', 'requested_at', 'processed_at', 'note'])
                ->where('referrer_user_id', $ownerUserId)
                ->orderByDesc('requested_at')
                ->limit(100)
                ->get();

            return response()->json([
                'ownerUserId' => $ownerUserId,
                'balances' => $balances,
                'requests' => $requests->map(fn ($row) => [
                    'id' => (string) $row->id,
                    'referrerUserId' => (string) $row->referrer_user_id,
                    'currency' => (string) $row->currency,
                    'amount' => (float) ($row->amount ?? 0),
                    'status' => (string) ($row->status ?? ''),
                    'requestedAt' => $row->requested_at,
                    'processedAt' => $row->processed_at,
                    'note' => $row->note,
                ])->values(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage() ?: 'failed'], 500);
        }
    }

    public function requestWithdrawal(Request $request)
    {
        try {
            $this->ensureWithdrawalTable();

            $ownerUserId = $this->pickOwnerId($request, true);
            if (!$ownerUserId) {
                return response()->json(['error' => 'ownerUserId required'], 400);
            }

            $currency = $this->normalizeCurrency((string) $request->input('currency', ''));
            $amount = (float) $request->input('amount', 0);

            if ($currency === '') {
                return response()->json(['error' => 'currency required'], 400);
            }

            if (!is_finite($amount) || $amount <= 0) {
                return response()->json(['error' => 'amount must be greater than 0'], 400);
            }

            $balances = $this->fetchWithdrawalBalances($ownerUserId);
            $balance = collect($balances)->firstWhere('currency', $currency);
            $available = (float) ($balance['availableAmount'] ?? 0);

            if ($amount > $available) {
                return response()->json([
                    'error' => 'insufficient available balance',
                    'available' => $available,
                    'currency' => $currency,
                ], 400);
            }

            $withdrawalId = (string) Str::uuid();

            DB::table('referral_withdrawals')->insert([
                'id' => $withdrawalId,
                'referrer_user_id' => $ownerUserId,
                'currency' => $currency,
                'amount' => $amount,
                'status' => 'requested',
                'requested_at' => now(),
                'processed_at' => null,
                'note' => null,
                'metadata' => json_encode(['source' => 'referrals_page']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'requestId' => $withdrawalId,
                'ownerUserId' => $ownerUserId,
                'currency' => $currency,
                'amount' => $amount,
                'balances' => $this->fetchWithdrawalBalances($ownerUserId),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage() ?: 'failed'], 500);
        }
    }

    public function updateWithdrawal(Request $request)
    {
        try {
            $this->ensureWithdrawalTable();

            $id = trim((string) $request->input('id', ''));
            $status = strtolower(trim((string) $request->input('status', '')));
            $note = $request->input('note');
            $note = $note === null ? null : trim((string) $note);

            if ($id === '') {
                return response()->json(['error' => 'id required'], 400);
            }

            $allowedStatuses = ['requested', 'processing', 'approved', 'paid', 'rejected'];
            if (!in_array($status, $allowedStatuses, true)) {
                return response()->json(['error' => 'invalid status'], 400);
            }

            $existing = DB::table('referral_withdrawals')->where('id', $id)->first();
            if (!$existing) {
                return response()->json(['error' => 'withdrawal request not found'], 404);
            }

            $currentStatus = strtolower((string) ($existing->status ?? ''));
            if ($currentStatus === 'paid') {
                return response()->json(['error' => 'paid withdrawal request is final and cannot be changed'], 409);
            }

            $shouldSetProcessedAt = in_array($status, ['paid', 'rejected'], true);

            DB::table('referral_withdrawals')
                ->where('id', $id)
                ->update([
                    'status' => $status,
                    'note' => $note,
                    'processed_at' => $shouldSetProcessedAt ? now() : $existing->processed_at,
                    'updated_at' => now(),
                ]);

            $row = DB::table('referral_withdrawals as rw')
                ->leftJoin('users as u', 'u.id', '=', 'rw.referrer_user_id')
                ->select(['rw.id', 'rw.referrer_user_id', 'u.email as referrer_email', 'rw.currency', 'rw.amount', 'rw.status', 'rw.requested_at', 'rw.processed_at', 'rw.note'])
                ->where('rw.id', $id)
                ->first();

            return response()->json([
                'success' => true,
                'request' => [
                    'id' => (string) $row->id,
                    'referrerUserId' => (string) $row->referrer_user_id,
                    'referrerEmail' => $row->referrer_email,
                    'currency' => (string) $row->currency,
                    'amount' => (float) ($row->amount ?? 0),
                    'status' => (string) ($row->status ?? ''),
                    'requestedAt' => $row->requested_at,
                    'processedAt' => $row->processed_at,
                    'note' => $row->note,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage() ?: 'failed'], 500);
        }
    }
}
