<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * الـ refresh token في كوكي HttpOnly بدل localStorage — فأي XSS مبقاش يقدر
 * يسرق credential عمره 14 يوم (الـ JS مبيشوفوش أصلًا).
 *
 * - uip_refresh : HttpOnly + Secure + SameSite، Path=/api/v1/auth بس (المتصفح
 *                 مبيبعتوش غير لـ refresh-token / logout / login...).
 * - uip_csrf    : مقروء من JS (مش HttpOnly). قيمته = HMAC(refresh token) فهو
 *                 ملزوق بالـ refresh token نفسه — "signed double-submit":
 *                 الطلب لازم يبعت X-CSRF-Token بنفس القيمة، والسيرفر يتأكد إنها
 *                 مطابقة للكوكي *و*لـ HMAC الـ refresh token اللي جاي في الكوكي.
 *                 يعني حتى لو حد زرع كوكي csrf من subdomain تاني مش هينفع.
 *
 * الـ access token لسه Bearer في الـ header (مفيش CSRF عليه).
 *
 * ENV:
 *   AUTH_REFRESH_TRANSPORT = cookie (افتراضي) | both
 *       both = كمان يرجّع refresh_token في جسم الرد (لعملاء مش متصفح فقط).
 *   AUTH_COOKIE_SAMESITE   = strict (افتراضي) | lax | none
 *       none = لما الـ API على دومين تاني عن الـ SPA (محتاج CORS credentials).
 *   AUTH_COOKIE_SECURE     = true/false (الافتراضي: true على https/production)
 *   AUTH_COOKIE_DOMAIN     = فاضي (host-only، الأأمن)
 *   AUTH_ALLOW_BODY_REFRESH= true (افتراضي) — يقبل refresh_token في الجسم عشان
 *       الجلسات القديمة اللي كانت في localStorage تتهاجر للكوكي. اقفله (false)
 *       بعد ما JWT_REFRESH_TTL (14 يوم) يعدي من النشر.
 *   AUTH_ALLOWED_ORIGINS   = origins إضافية مسموحة (CORS_ALLOWED_ORIGINS بتتحسب كمان).
 */
class AuthCookies
{
    public const REFRESH_COOKIE = 'uip_refresh';
    public const CSRF_COOKIE    = 'uip_csrf';
    public const CSRF_HEADER    = 'X-CSRF-Token';
    public const COOKIE_PATH    = '/api/v1/auth';

    // ---- القراءة ---------------------------------------------------------

    public static function refreshFromCookie(Request $request): string
    {
        // نقرا الكوكي الخام (الـ api group مفيهوش EncryptCookies).
        $value = $request->cookies->get(self::REFRESH_COOKIE);
        return is_string($value) ? $value : '';
    }

    public static function allowBodyRefresh(): bool
    {
        return filter_var(env('AUTH_ALLOW_BODY_REFRESH', true), FILTER_VALIDATE_BOOLEAN);
    }

    public static function exposeRefreshInBody(): bool
    {
        return strtolower((string) env('AUTH_REFRESH_TRANSPORT', 'cookie')) === 'both';
    }

    // ---- CSRF ------------------------------------------------------------

    public static function csrfFor(string $rawRefresh): string
    {
        return hash_hmac('sha256', 'uip-csrf|' . $rawRefresh, self::secret());
    }

    /**
     * يرجّع null لو الطلب سليم، أو كود سبب الرفض.
     * بيتنادى بس لما الـ refresh token جاي من الكوكي.
     */
    public static function csrfFailure(Request $request): ?string
    {
        $raw = self::refreshFromCookie($request);
        if ($raw === '') {
            return 'csrf_no_cookie';
        }

        $header = (string) $request->header(self::CSRF_HEADER, '');
        $cookie = (string) $request->cookies->get(self::CSRF_COOKIE, '');
        if ($header === '' || $cookie === '') {
            return 'csrf_missing';
        }

        $expected = self::csrfFor($raw);
        if (!hash_equals($expected, $header) || !hash_equals($expected, $cookie)) {
            return 'csrf_mismatch';
        }

        // طبقة زيادة: لو الـ Origin اتبعت ومعانا allowlist، لازم يكون منها.
        $origin = (string) $request->headers->get('Origin', '');
        $allowed = self::allowedOrigins();
        if ($origin !== '' && !empty($allowed) && !in_array(rtrim($origin, '/'), $allowed, true)) {
            return 'origin_not_allowed';
        }

        return null;
    }

    /** @return string[] origins صريحة (من غير wildcard). فاضية = مفيش allowlist مظبوطة. */
    public static function allowedOrigins(): array
    {
        $list = [];
        foreach (['CORS_ALLOWED_ORIGINS', 'AUTH_ALLOWED_ORIGINS'] as $key) {
            foreach (explode(',', (string) env($key, '')) as $o) {
                $o = rtrim(trim($o), '/');
                if ($o !== '' && $o !== '*') {
                    $list[] = $o;
                }
            }
        }
        $front = rtrim(trim((string) config('app.frontend_url', '')), '/');
        if ($front !== '' && !empty($list)) {
            $list[] = $front;
        }
        return array_values(array_unique($list));
    }

    // ---- الإصدار / المسح ---------------------------------------------------

    /**
     * لو الرد فيه data.refresh_token: يحطه في كوكي HttpOnly ويحط كوكي الـ CSRF،
     * ويشيل التوكن من الجسم (إلا لو AUTH_REFRESH_TRANSPORT=both).
     */
    public static function attach(Response $response, Request $request): Response
    {
        $response = self::noStore($response);

        if (!$response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(); // objects — عشان meta: {} ميتحولش لـ []
        $raw = $payload->data->refresh_token ?? null;
        if (!is_object($payload) || !is_string($raw) || $raw === '') {
            return $response;
        }

        $ttl = (int) env('JWT_REFRESH_TTL', 1209600);
        $expires = time() + $ttl;

        $response->headers->setCookie(self::makeCookie(self::REFRESH_COOKIE, $raw, $expires, self::COOKIE_PATH, true, $request));
        $response->headers->setCookie(self::makeCookie(self::CSRF_COOKIE, self::csrfFor($raw), $expires, '/', false, $request));

        if (!self::exposeRefreshInBody()) {
            unset($payload->data->refresh_token);
            $response->setData($payload);
        }

        return $response;
    }

    public static function clear(Response $response, Request $request): Response
    {
        $response = self::noStore($response);
        $response->headers->setCookie(self::makeCookie(self::REFRESH_COOKIE, '', 1, self::COOKIE_PATH, true, $request));
        $response->headers->setCookie(self::makeCookie(self::CSRF_COOKIE, '', 1, '/', false, $request));
        return $response;
    }

    public static function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');
        return $response;
    }

    // ---- داخلي -----------------------------------------------------------

    private static function makeCookie(string $name, string $value, int $expires, string $path, bool $httpOnly, Request $request): Cookie
    {
        $sameSite = strtolower((string) env('AUTH_COOKIE_SAMESITE', 'strict'));
        if (!in_array($sameSite, ['strict', 'lax', 'none'], true)) {
            $sameSite = 'strict';
        }

        $secureEnv = env('AUTH_COOKIE_SECURE');
        $secure = $secureEnv !== null && $secureEnv !== ''
            ? filter_var($secureEnv, FILTER_VALIDATE_BOOLEAN)
            : ($request->isSecure() || app()->environment('production'));

        // SameSite=None من غير Secure المتصفح بيرفضه.
        if ($sameSite === 'none') {
            $secure = true;
        }

        $domain = trim((string) env('AUTH_COOKIE_DOMAIN', ''));

        return new Cookie(
            $name,
            $value,
            $expires,
            $path,
            $domain !== '' ? $domain : null,
            $secure,
            $httpOnly,
            false,
            $sameSite
        );
    }

    private static function secret(): string
    {
        $key = (string) config('app.key', '');
        if ($key === '') {
            $key = (string) env('JWT_SECRET', '');
        }
        if ($key === '') {
            throw new \RuntimeException('APP_KEY or JWT_SECRET must be set to issue auth cookies.');
        }
        return $key;
    }
}
