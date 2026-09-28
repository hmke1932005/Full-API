<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * نسخة طبق الأصل من app/Middleware/CorsMiddleware.php القديمة: بترجّع
 * الـ Origin بتاع الريكوست نفسه (مقيّد اختياريًا بـ CORS_ALLOWED_ORIGINS)
 * بدل قيمة ثابتة واحدة، عشان الـ API يشتغل من أي origin مسموح بيه (dev
 * وprod) مش بس APP_URL. هنا كمان Bearer JWT بس (مفيش cookies) فمفيش
 * تعارض credentialed-request/wildcard زي ما كان موثّق في القديم.
 *
 * ⚠️ فرق تسجيل متعمّد عن القديم: القديم كان بيقفل الـ preflight (OPTIONS)
 * في core/App.php *قبل* الـ Router عشان الـ Router بتاعه مكنش بيسجل
 * OPTIONS أصلًا. هنا مسجّلة كـ Global middleware في bootstrap/app.php
 * (مش route middleware) بالظبط لنفس السبب: أي OPTIONS لروت مش مسجّل هيوصل
 * لغاية هنا قبل ما لارافيل يرمي 404، فهي بتقفله بنفس الطريقة. شوف
 * README.md قسم "تسجيل الـ Middleware" للتفاصيل.
 */
class UipCorsMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->attachHeaders(response('', 204), $request);
        }

        $response = $next($request);

        return $this->attachHeaders($response, $request);
    }

    private function attachHeaders(Response $response, Request $request): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', $this->resolveAllowedOrigin($request));
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        $response->headers->set('Vary', 'Origin');

        return $response;
    }

    /**
     * - CORS_ALLOWED_ORIGINS فاضي (الافتراضي): بيرجّع أي Origin بعت بيه
     *   الريكوست.
     * - CORS_ALLOWED_ORIGINS="https://app.example.com,http://localhost:5173"
     *   (مفصولة بفاصلة): بيرجّع الـ Origin بس لو موجود في اللستة؛ غير كده
     *   بيرجع config('app.url').
     */
    private function resolveAllowedOrigin(Request $request): string
    {
        $origin = $request->header('Origin');
        $allowList = array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))));

        if (!$origin) {
            return (string) config('app.url', '*');
        }

        if (empty($allowList)) {
            return $origin;
        }

        return in_array($origin, $allowList, true) ? $origin : (string) config('app.url', '*');
    }
}
