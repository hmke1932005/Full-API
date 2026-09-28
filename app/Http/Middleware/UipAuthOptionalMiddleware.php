<?php

namespace App\Http\Middleware;

use App\Services\UipJwtService;
use Closure;
use Illuminate\Http\Request;

/**
 * نسخة اختيارية من UipAuthMiddleware — Round 2 (Lobby & Access)، لسطح
 * "الدخول للاجتماع" العام (join_token) اللي لازم يشتغل لمستخدم UIP
 * مسجّل *أو* لضيف من غير حساب خالص (بند 23). لو فيه Bearer token صالح،
 * بتحط uip_user_id/uip_role بالظبط زي uip.auth العادي. لو مفيش
 * Authorization header، أو فيه واحد لكن التوكن باظ/منتهي، الريكوست
 * بيكمل عادي من غير ما تتحط الـ attributes دي (مش 401) — الميدلوير دي
 * عمدًا مش بتقرر "الضيوف مسموحين ولا لأ"، ده قرار الكنترولر/الخدمة على
 * مستوى كل اجتماع لوحده (allow_guests)، زي ما بند 23 نص "Guest access
 * must be configurable per meeting".
 */
class UipAuthOptionalMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $header = $request->header('Authorization', '');

        if (str_starts_with($header, 'Bearer ')) {
            $claims = UipJwtService::decode(substr($header, 7));
            if ($claims && isset($claims['sub'])) {
                $request->attributes->set('uip_user_id', (int) $claims['sub']);
                $request->attributes->set('uip_role', (string) ($claims['role'] ?? ''));
            }
        }

        return $next($request);
    }
}
