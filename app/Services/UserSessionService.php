<?php

namespace App\Services;

use App\Models\RefreshToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * تسجيل الجلسات الحقيقية في `user_sessions` — كانت مش بتتكتب خالص، فصفحة
 * Sessions في بورتال الأمان كانت فاضية.
 *
 * الجلسة = سلسلة refresh tokens لجهاز واحد. session_token = sha256 لـ refresh
 * token الحالي (بيتبدّل مع كل rotation)، والـ access token بيشيل claim
 * `sid` = id الصف، فالـ middleware يقدر يتأكد إن الجلسة لسه شغالة وبالتالي
 * الـ Revoke بيطرد المستخدم فعلًا (مش بس يغيّر علامة في الجدول).
 */
class UserSessionService
{
    private const TOUCH_EVERY_SECONDS = 60;

    public function __construct(
        private GeoIpService $geo,
        private SecurityAlertService $alerts
    ) {
    }

    /** يفتح جلسة جديدة بعد تسجيل دخول ناجح. @return int session id */
    public function start(int $userId, string $rawRefresh, int $refreshTtlSeconds): int
    {
        $request = request();
        $ip = $request?->ip();
        $ua = (string) ($request?->userAgent() ?? '');
        $location = $this->locationLabel($ip);

        $id = (int) DB::table('user_sessions')->insertGetId([
            'user_id'          => $userId,
            'session_token'    => hash('sha256', $rawRefresh),
            'ip_address'       => $ip,
            'user_agent'       => $ua !== '' ? mb_substr($ua, 0, 255) : null,
            'device_label'     => $this->deviceLabel($ua),
            'location_label'   => $location,
            'is_active'        => 1,
            'last_activity_at' => now(),
            'created_at'       => now(),
            'expires_at'       => now()->addSeconds($refreshTtlSeconds),
        ]);

        try {
            $email = (string) DB::table('users')->where('id', $userId)->value('email');
            $this->alerts->newSession($userId, $email, $ip, $location, $id);
        } catch (\Throwable $e) {
            Log::warning('Session alert check failed: ' . $e->getMessage());
        }

        return $id;
    }

    /** Refresh: نفس الجلسة، refresh token جديد. */
    public function rotate(int $sessionId, string $newRawRefresh, int $refreshTtlSeconds): void
    {
        DB::table('user_sessions')->where('id', $sessionId)->update([
            'session_token'    => hash('sha256', $newRawRefresh),
            'last_activity_at' => now(),
            'expires_at'       => now()->addSeconds($refreshTtlSeconds),
        ]);
    }

    public function findByRefreshHash(string $hash): ?object
    {
        return DB::table('user_sessions')->where('session_token', $hash)->first() ?: null;
    }

    /** هل الجلسة لسه شغالة؟ التوكنات القديمة (من غير sid) بتعدّي. */
    public function isActive(?int $sessionId): bool
    {
        if (!$sessionId) {
            return true;
        }
        $row = DB::table('user_sessions')->where('id', $sessionId)->first(['is_active', 'expires_at']);
        if (!$row) {
            return false;
        }
        return (bool) $row->is_active && (!$row->expires_at || strtotime((string) $row->expires_at) > time());
    }

    /** يحدّث آخر نشاط بحد أقصى مرة كل دقيقة لكل جلسة. */
    public function touch(int $sessionId): void
    {
        $key = 'sess:touch:' . $sessionId;
        if (Cache::add($key, 1, self::TOUCH_EVERY_SECONDS)) {
            DB::table('user_sessions')->where('id', $sessionId)->where('is_active', 1)->update(['last_activity_at' => now()]);
        }
    }

    /** إنهاء جلسة (logout / revoke) + إلغاء الـ refresh token بتاعها. */
    public function end(int $sessionId, string $reason, ?int $revokedBy = null): bool
    {
        $row = DB::table('user_sessions')->where('id', $sessionId)->first();
        if (!$row) {
            return false;
        }
        DB::table('user_sessions')->where('id', $sessionId)->update([
            'is_active'     => 0,
            'revoked_by'    => $revokedBy,
            'revoked_at'    => now(),
            'revoke_reason' => $reason,
        ]);
        RefreshToken::where('token_hash', $row->session_token)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        return true;
    }

    /** كل جلسات مستخدم (refresh token reuse مثلًا). */
    public function endAllForUser(int $userId, string $reason): void
    {
        DB::table('user_sessions')->where('user_id', $userId)->where('is_active', 1)->update([
            'is_active' => 0, 'revoked_at' => now(), 'revoke_reason' => $reason,
        ]);
    }

    private function locationLabel(?string $ip): ?string
    {
        if (!$ip) {
            return null;
        }
        try {
            $r = $this->geo->resolve($ip);
        } catch (\Throwable) {
            return null;
        }
        if ($r['status'] === 'ok') {
            return $r['name'] ?: $r['code'];
        }
        return $r['status'] === 'local' ? 'Local network' : null;
    }

    private function deviceLabel(string $ua): ?string
    {
        if ($ua === '') {
            return null;
        }
        $browser = 'Browser';
        foreach ([
            'Edg/' => 'Edge', 'OPR/' => 'Opera', 'Firefox/' => 'Firefox',
            'Chrome/' => 'Chrome', 'Safari/' => 'Safari', 'curl/' => 'curl',
        ] as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                $browser = $name;
                break;
            }
        }
        $os = 'Unknown OS';
        foreach ([
            'Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iPadOS',
            'Mac OS X' => 'macOS', 'Linux' => 'Linux',
        ] as $needle => $name) {
            if (stripos($ua, $needle) !== false) {
                $os = $name;
                break;
            }
        }
        return "{$browser} on {$os}";
    }
}
