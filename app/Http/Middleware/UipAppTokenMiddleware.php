<?php

namespace App\Http\Middleware;

use App\Repositories\ApiTokenRepository;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * قفل تطبيق الأندرويد (APK) بـ API token صادر من صفحة /admin/mobile.
 *
 * التطبيق (src/native.js في الفرونت) بيبعت مع كل طلب:
 *   X-UIP-Client: android      ← علامة إن الطلب جاي من التطبيق مش من المتصفح
 *   X-App-Token:  <raw token>  ← التوكن اللي اتبنى جوه الـ APK (VITE_APP_TOKEN)
 *
 * السلوك:
 *  - APP_TOKEN_REQUIRED=false (الافتراضي): مفيش منع. لو التوكن وصل وصالح بس بنسجّل last_used_at
 *    (عشان تتأكد إن التوكن شغال قبل ما تفعّل القفل).
 *  - APP_TOKEN_REQUIRED=true: أي طلب معلَّم كتطبيق من غير توكن صالح (مفقود / غلط / ملغي) → 403
 *    بكود app_token_invalid، والتطبيق بيعرض شاشة "التطبيق غير مصرّح".
 *  - APP_TOKEN_BLOCK_WEBVIEW=true (اختياري): بيعتبر أي Android WebView ("; wv)" في الـ User-Agent)
 *    طلب تطبيق حتى لو مبعتش العلامة، فبيقفل نسخ APK القديمة اللي اتبنت قبل القفل. تحذير: متصفحات
 *    داخل تطبيقات تانية (فيسبوك/إنستجرام/جيميل على أندرويد) بتستخدم WebView كمان فهتتقفل معاها.
 *
 * ملحوظة أمان: التوكن جوه الـ APK ممكن يتستخرج بفك الملف. ده بيمنع النسخ اللي معندهاش توكن
 * والنسخ المعدّلة/القديمة بعد الإلغاء، مش بديل لتسجيل الدخول. الإلغاء من /admin/mobile بيقفل
 * كل APK بنفس التوكن (تأخير أقصى ~60 ثانية بسبب الـ cache).
 */
class UipAppTokenMiddleware
{
    public function __construct(private ApiTokenRepository $tokens)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('OPTIONS')) {
            return $next($request); // preflight — بيتعامل معاه UipCorsMiddleware
        }

        if ($request->is('api/v1/app-config')) {
            return $next($request); // this is where the app GETS its token, so it cannot require one
        }

        $raw = trim((string) $request->header('X-App-Token', ''));
        $valid = $raw !== '' && $this->isValid($raw);

        if (!config('site.app_token_required')) {
            return $next($request);
        }

        $ua = (string) $request->userAgent();
        $isApp = $request->headers->has('X-UIP-Client')
            || (config('site.app_token_block_webview') && str_contains($ua, '; wv)'));

        if ($isApp && !$valid) {
            return response()->json([
                'success' => false,
                'message' => 'This app build is not authorized. Please install the latest official version.',
                'data'    => null,
                'errors'  => null,
                'meta'    => (object) [],
                'code'    => 'app_token_invalid',
            ], 403);
        }

        return $next($request);
    }

    private function isValid(string $raw): bool
    {
        $hash = hash('sha256', $raw);
        // cache قصير: مفيش DB hit في كل ريكوست، والإلغاء بيسري في أقل من دقيقة.
        $id = Cache::remember('app_token:' . $hash, 60, function () use ($raw) {
            return $this->tokens->findActiveByRawToken($raw)?->id ?? 0;
        });
        if (!$id) {
            Cache::forget('app_token:' . $hash); // ما نكاشيش الرفض، عشان توكن جديد يشتغل فورًا
            return false;
        }
        if (Cache::add('app_token_seen:' . $id, 1, 600)) {
            DB::table('api_tokens')->where('id', $id)->update(['last_used_at' => now()]);
        }
        return true;
    }
}
