<?php

namespace App\Http\Middleware;

use App\Services\UipJwtService;
use App\Services\UserSessionService;
use Closure;
use Illuminate\Http\Request;

/**
 * نسخة اختيارية من UipAuthMiddleware (meetings/join, signaling, chat, files...).
 * لو فيه Bearer صالح *وجلسته لسه شغالة* بتحط uip_user_id/uip_role.
 * لو التوكن باظ أو الجلسة اتلغت (logout/revoke) بيتعامل معاه كضيف — مش 401.
 *
 * التعديل: قبل كده كانت بتتجاهل `sid` فتوكن بعد الـ logout يفضل بيتعرّف كمستخدم هنا.
 */
class UipAuthOptionalMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $header = $request->header('Authorization', '');

        if (str_starts_with($header, 'Bearer ')) {
            $claims = UipJwtService::decode(substr($header, 7));
            if ($claims && isset($claims['sub'])) {
                $sid = isset($claims['sid']) ? (int) $claims['sid'] : null;
                $requireSid = filter_var(env('AUTH_REQUIRE_SID', false), FILTER_VALIDATE_BOOLEAN);

                $valid = true;
                if ($sid) {
                    $valid = app(UserSessionService::class)->isActive($sid);
                } elseif ($requireSid) {
                    $valid = false;
                }

                if ($valid) {
                    if ($sid) {
                        $request->attributes->set('uip_session_id', $sid);
                    }
                    $request->attributes->set('uip_user_id', (int) $claims['sub']);
                    $request->attributes->set('uip_role', (string) ($claims['role'] ?? ''));
                }
            }
        }

        return $next($request);
    }
}
