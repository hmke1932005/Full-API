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
        $tokenId = $raw !== '' ? $this->activeId($raw) : 0;
        $valid = $tokenId > 0;
        // توكن ملغي بيحاول يدخل: بنسجّله برضه (outcome=revoked) عشان تشوف مين لسه بيستخدمه.
        $revokedId = (!$valid && $raw !== '') ? $this->revokedId($raw) : 0;

        $response = null;
        if (config('site.app_token_required')) {
            $ua = (string) $request->userAgent();
            $isApp = $request->headers->has('X-UIP-Client')
                || (config('site.app_token_block_webview') && str_contains($ua, '; wv)'));

            if ($isApp && !$valid) {
                $response = response()->json([
                    'success' => false,
                    'message' => 'This app build is not authorized. Please install the latest official version.',
                    'data'    => null,
                    'errors'  => null,
                    'meta'    => (object) [],
                    'code'    => 'app_token_invalid',
                ], 403);
            }
        }
        $response ??= $next($request);

        // بعد الرد: وقتها uip.auth يكون خلّص وعارفين مين المستخدم.
        if ($tokenId || $revokedId) {
            $this->logUsage($request, $response, $tokenId ?: $revokedId, $valid ? 'ok' : 'revoked');
        }

        return $response;
    }

    /** id التوكن الفعال (0 لو مش صالح). بيحدّث last_used_at كل 10 دقايق. */
    private function activeId(string $raw): int
    {
        $hash = hash('sha256', $raw);
        // cache قصير: مفيش DB hit في كل ريكوست، والإلغاء بيسري في أقل من دقيقة.
        $id = (int) Cache::remember('app_token:' . $hash, 60, function () use ($raw) {
            return $this->tokens->findActiveByRawToken($raw)?->id ?? 0;
        });
        if (!$id) {
            Cache::forget('app_token:' . $hash); // ما نكاشيش الرفض، عشان توكن جديد يشتغل فورًا
            return 0;
        }
        if (Cache::add('app_token_seen:' . $id, 1, 600)) {
            DB::table('api_tokens')->where('id', $id)->update(['last_used_at' => now()]);
        }
        return $id;
    }

    private function revokedId(string $raw): int
    {
        $hash = hash('sha256', $raw);
        return (int) Cache::remember('app_token_revoked:' . $hash, 60, function () use ($hash) {
            return (int) (DB::table('api_tokens')->where('token_hash', $hash)->whereNotNull('revoked_at')->value('id') ?? 0);
        });
    }

    /** صف في api_token_usages — فشله ما يوقّفش الطلب أبدًا. بدون query string (ممكن فيها أسرار). */
    private function logUsage(Request $request, $response, int $tokenId, string $outcome): void
    {
        try {
            DB::table('api_token_usages')->insert([
                'api_token_id' => $tokenId,
                'user_id'      => $request->attributes->get('uip_user_id') ?: null,
                'method'       => substr($request->method(), 0, 10),
                'path'         => substr('/' . ltrim($request->path(), '/'), 0, 255),
                'route'        => ($uri = $request->route()?->uri()) ? substr('/' . ltrim($uri, '/'), 0, 255) : null,
                'status'       => (int) $response->getStatusCode(),
                'outcome'      => $outcome,
                'ip'           => substr((string) $request->ip(), 0, 45) ?: null,
                'user_agent'   => substr((string) $request->userAgent(), 0, 255) ?: null,
                'client'       => substr((string) $request->header('X-UIP-Client', ''), 0, 40) ?: null,
                'device_id'    => substr((string) $request->header('X-Device-Id', ''), 0, 64) ?: null,
                'created_at'   => now(),
            ]);

            // تنضيف تلقائي: 1% من الطلبات بتمسح السجل الأقدم من المدة المحددة.
            if (random_int(1, 100) === 1) {
                $days = max(1, (int) config('site.app_token_log_days', 60));
                DB::table('api_token_usages')->where('created_at', '<', now()->subDays($days))->delete();
            }
        } catch (\Throwable $e) {
            // التسجيل ثانوي: لو الجدول لسه ما اتعملوش (migration) أو حصل خطأ، الطلب يكمل عادي.
        }
    }
}
