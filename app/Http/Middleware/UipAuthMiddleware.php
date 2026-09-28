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
        $claims = UipJwtService::decode($token);

        if (!$claims || !isset($claims['sub'])) {
            return response()->json([
                'success' => false,
                'message' => 'Your session has expired. Please log in again.',
                'data'    => null,
                'errors'  => null,
                'meta'    => (object) [],
            ], 401);
        }

        $request->attributes->set('uip_user_id', (int) $claims['sub']);
        $request->attributes->set('uip_role', (string) ($claims['role'] ?? ''));

        return $next($request);
    }
}
