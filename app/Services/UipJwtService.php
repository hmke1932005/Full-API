<?php

namespace App\Services;

/**
 * نسخة طبق الأصل من core/Jwt.php بتاع المشروع القديم — نفس الـ HS256
 * بالظبط، نفس base64url، نفس ترتيب json_encode. الهدف: توكن صادر من
 * القديم يفضل شغال هنا، وتوكن صادر من هنا يفضل شغال هناك، طول فترة
 * الانتقال (طالما JWT_SECRET نفسه في الاتنين).
 */
class UipJwtService
{
    private static function secret(): string
    {
        $secret = env('JWT_SECRET');
        if (!$secret) {
            throw new \RuntimeException('JWT_SECRET is not configured.');
        }
        return (string) $secret;
    }

    private static function b64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64UrlDecode(string $data): string
    {
        $padded = str_pad($data, strlen($data) % 4 === 0 ? strlen($data) : strlen($data) + 4 - (strlen($data) % 4), '=');
        return (string) base64_decode(strtr($padded, '-_', '+/'));
    }

    /** @param array<string,mixed> $claims */
    public static function encode(array $claims, int $ttlSeconds): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $now = time();
        $payload = array_merge($claims, ['iat' => $now, 'exp' => $now + $ttlSeconds]);

        $segments = [
            self::b64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::b64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];
        $signature = hash_hmac('sha256', implode('.', $segments), self::secret(), true);
        $segments[] = self::b64UrlEncode($signature);

        return implode('.', $segments);
    }

    /** @return array<string,mixed>|null null لو التوكن باظ أو الصلاحية خلصت أو التوقيع غلط. */
    public static function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$headerB64, $payloadB64, $sigB64] = $parts;

        $expectedSig = self::b64UrlEncode(
            hash_hmac('sha256', $headerB64 . '.' . $payloadB64, self::secret(), true)
        );
        if (!hash_equals($expectedSig, $sigB64)) {
            return null;
        }

        $payload = json_decode(self::b64UrlDecode($payloadB64), true);
        if (!is_array($payload) || !isset($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    /**
     * يصدر زوج access + refresh بنفس شكل AuthService::issueApiTokens() بالظبط.
     *
     * 'name'/'email' بينضافوا كـ claims هنا (بالإضافة لـ sub/role الأصليين)
     * عشان الفرونت (AuthContext.jsx::decodeJwt) يقدر يعرض اسم/إيميل
     * المستخدم الحقيقي بدل ما يعتمد على full_name/email مش موجودين أصلاً
     * في الـ user object بتاعه (كانوا بيرجعوا undefined دايمًا في أي
     * مكان بيحاول يعرض اسم المستخدم من الـ JWT، مش بس Meeting Room —
     * راجع نقاش الباگ ده). لسه اختياريين (null لو المستخدم اتمسح بين
     * إصدار التوكن القديم واستخدامه) عشان decode() القديم يفضل متوافق.
     */
    public static function issueTokenPair(int $userId, string $role, ?int $sessionId = null): array
    {
        $accessTtl  = (int) env('JWT_ACCESS_TTL', 900);
        $refreshTtl = (int) env('JWT_REFRESH_TTL', 1209600);

        $user = \App\Models\User::find($userId);

        $rawRefresh  = bin2hex(random_bytes(32));

        // جلسة حقيقية في user_sessions: جديدة عند اللوجين، أو نفس الجلسة
        // (rotation) عند الـ refresh. الـ sid بيتحط في الـ access token عشان
        // الـ Revoke من بورتال الأمان يطرد المستخدم فعلًا.
        $sessions = app(\App\Services\UserSessionService::class);
        try {
            if ($sessionId) {
                $sessions->rotate($sessionId, $rawRefresh, $refreshTtl);
            } else {
                $sessionId = $sessions->start($userId, $rawRefresh, $refreshTtl);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Session tracking failed: ' . $e->getMessage());
            $sessionId = null;
        }

        $claims = [
            'sub'   => $userId,
            'role'  => $role,
            'name'  => $user->full_name ?? null,
            'email' => $user->email ?? null,
        ];
        if ($sessionId) {
            $claims['sid'] = $sessionId;
        }
        $accessToken = self::encode($claims, $accessTtl);

        \App\Models\RefreshToken::create([
            'user_id'      => $userId,
            'token_hash'   => hash('sha256', $rawRefresh),
            'device_label' => request()->userAgent() ? substr(request()->userAgent(), 0, 150) : null,
            'ip_address'   => request()->ip(),
            'expires_at'   => now()->addSeconds($refreshTtl),
        ]);

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $rawRefresh,
            'token_type'    => 'Bearer',
            'expires_in'    => $accessTtl,
            // الفرونت بيستخدمها لقفل الجلسة لما المستخدم يسيب المنصة من غير أي تفاعل.
            'idle_timeout_minutes' => (int) round(app(\App\Services\SessionPolicyService::class)->idleTimeoutSeconds() / 60),
        ];
    }
}
