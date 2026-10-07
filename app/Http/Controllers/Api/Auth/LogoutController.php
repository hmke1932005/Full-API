<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\RefreshToken;
use App\Services\UserSessionService;
use App\Support\AuthCookies;
use App\Support\SecurityLog;
use Illuminate\Http\Request;

/** يطابق LogoutController::handle() القديم بالظبط (شكل رد بسيط، مش envelope كامل). */
class LogoutController extends Controller
{
    public function __construct(private UserSessionService $sessions)
    {
    }

    public function handle(Request $request)
    {
        // الكوكي هو المصدر؛ الجسم للجلسات القديمة (localStorage) بس.
        $refreshToken = AuthCookies::refreshFromCookie($request);
        if ($refreshToken !== '') {
            if ($reason = AuthCookies::csrfFailure($request)) {
                SecurityLog::write('Logout rejected — CSRF check failed', ['reason' => $reason, 'ip' => $request->ip()]);
                return AuthCookies::noStore(response()->json(['success' => false, 'message' => 'Request could not be verified.'], 403));
            }
        } elseif (AuthCookies::allowBodyRefresh()) {
            $refreshToken = (string) $request->input('refresh_token', '');
        }
        if ($refreshToken !== '') {
            $row = RefreshToken::where('token_hash', hash('sha256', $refreshToken))
                ->whereNull('revoked_at')
                ->first();
            $session = $this->sessions->findByRefreshHash(hash('sha256', $refreshToken));
            if ($session) {
                $this->sessions->end((int) $session->id, 'logout');
            }
            if ($row) {
                $row->update(['revoked_at' => now()]);
                SecurityLog::write('User logged out', ['user_id' => $row->user_id]);
            }
        }

        return AuthCookies::clear(response()->json(['success' => true, 'redirect' => '/auth/login']), $request);
    }
}
