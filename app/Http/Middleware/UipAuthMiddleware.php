<?php

namespace App\Http\Middleware;

use App\Services\UipJwtService;
use Closure;
use Illuminate\Http\Request;

/**
 * يطابق سلوك AuthMiddleware القديمة لكن بس على الجزء الخاص بـ Bearer
 * token (JSON API) — التحقق من الـ session cookie للويب الـ server-rendered
 * مش منقول هنا لأننا بنغطي الـ React SPA بس.
 *
 * لو التوكن صالح، بيحط $request->attributes: uip_user_id و uip_role
 * (بديل بسيط عن Auth::user() لحد ما نربط Guard كامل).
 */
class UipAuthMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $header = $request->header('Authorization', '');

        if (!str_starts_with($header, 'Bearer ')) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.',
                'data'    => null,
                'errors'  => null,
                'meta'    => (object) [],
            ], 401);
        }

        $token = substr($header, 7);
        $claims = UipJwtService::decodeAccess($token);

        if (!$claims || !isset($claims['sub'])) {
            return response()->json([
                'success' => false,
                'message' => 'Your session has expired. Please log in again.',
                'data'    => null,
                'errors'  => null,
                'meta'    => (object) [],
            ], 401);
        }

        // Device Restrictions Policy — بتتطبق على كل ريكوست محمي، فالجلسات اللي كانت
        // شغالة على نوع جهاز اتحظر بعد كده بتتقطع فورًا (مش بس اللوجين الجديد).
        $devicePolicy = app(\App\Services\DeviceRestrictionPolicyService::class);
        if (!$devicePolicy->isAllowed($request->userAgent())) {
            return response()->json([
                'success' => false,
                'message' => $devicePolicy->blockedMessage($request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en'),
                'data'    => ['device_blocked' => true],
                'errors'  => ['code' => 'device_blocked'],
                'meta'    => (object) [],
            ], 403);
        }

        // جلسة اتلغت من بورتال الأمان (أو خلصت) => الـ access token بيبطل فورًا.
        $sid = isset($claims['sid']) ? (int) $claims['sid'] : null;
        // توكن من غير sid مينفعش يتلغى بالـ logout — نرفضه لو AUTH_REQUIRE_SID=true.
        if (!$sid && filter_var(env('AUTH_REQUIRE_SID', false), FILTER_VALIDATE_BOOLEAN)) {
            return response()->json([
                'success' => false,
                'message' => 'Your session has expired. Please log in again.',
                'data'    => null,
                'errors'  => null,
                'meta'    => (object) [],
            ], 401);
        }
        if ($sid) {
            $sessions = app(\App\Services\UserSessionService::class);
            if (!$sessions->isActive($sid)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This session was ended. Please log in again.',
                    'data'    => null,
                    'errors'  => null,
                    'meta'    => (object) [],
                ], 401);
            }
            $sessions->touch($sid);
            $request->attributes->set('uip_session_id', $sid);
        }

        $request->attributes->set('uip_user_id', (int) $claims['sub']);
        $request->attributes->set('uip_role', (string) ($claims['role'] ?? ''));

        return $next($request);
    }
}
