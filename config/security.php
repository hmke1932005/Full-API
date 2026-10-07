<?php
/**
 * نسخة من config/security.php القديمة — الأجزاء المستخدمة في بند 2
 * (Infra) بس: rate_limit_per_min (fallback الـ UipRateLimitMiddleware)
 * وsecurity_headers (UipSecurityHeadersMiddleware). csrf_enabled مش
 * منقول: الـ access token Bearer (مفيش CSRF عليه)، لكن الـ refresh
 * token بقى في كوكي HttpOnly فـ refresh/logout محميين بـ double-submit CSRF
 * (X-CSRF-Token) + SameSite — شوف App\Support\AuthCookies. geoip بند 25 batch 3 (Country
 * Restrictions) — نفس مفاتيح القديمة بالظبط، GeoIpService بيقراها.
 */

return [
    'rate_limit_per_min' => (int) env('RATE_LIMIT_PER_MIN', 60),

    // سقف مستقل لطلبات محاولة الامتحان الشغالة (attempts/{id}/answers|security-events|submit)
    // — أعلى من العام عشان الحفظ التلقائي ميتعطّلش بـ 429 في نص الامتحان.
    'exam_attempt_rate_limit_per_min' => (int) env('EXAM_ATTEMPT_RATE_LIMIT_PER_MIN', 240),

    'security_headers' => [
        'X-Frame-Options'        => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        // الـ API بيرجّع JSON/ملفات بس: لو رد اتفتح كصفحة، أي سكريبت/frame ممنوع.
        'Content-Security-Policy' => "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
    ],

    'geoip' => [
        'enabled'      => (bool) env('GEOIP_ENABLED', true),
        'provider_url' => env('GEOIP_PROVIDER_URL', 'https://ipapi.co/{ip}/country/'),
        'timeout'      => (int) env('GEOIP_TIMEOUT', 3),
        'cache_days'   => (int) env('GEOIP_CACHE_DAYS', 30),
    ],
];
