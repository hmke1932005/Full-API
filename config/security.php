<?php
/**
 * نسخة من config/security.php القديمة — الأجزاء المستخدمة في بند 2
 * (Infra) بس: rate_limit_per_min (fallback الـ UipRateLimitMiddleware)
 * وsecurity_headers (UipSecurityHeadersMiddleware). csrf_enabled مش
 * منقول لأن الـ API الجديد Bearer-JWT فقط (مفيش cookies) فمفيش CSRF
 * يتحمى منه أصلًا — نفس القرار اللي اتاخد في موديول Auth (بند 1) وملوش
 * أي middleware اسمها CSRF هنا. geoip بند 25 batch 3 (Country
 * Restrictions) — نفس مفاتيح القديمة بالظبط، GeoIpService بيقراها.
 */

return [
    'rate_limit_per_min' => (int) env('RATE_LIMIT_PER_MIN', 60),

    'security_headers' => [
        'X-Frame-Options'        => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
    ],

    'geoip' => [
        'enabled'      => (bool) env('GEOIP_ENABLED', true),
        'provider_url' => env('GEOIP_PROVIDER_URL', 'https://ipapi.co/{ip}/country/'),
        'timeout'      => (int) env('GEOIP_TIMEOUT', 3),
        'cache_days'   => (int) env('GEOIP_CACHE_DAYS', 30),
    ],
];
