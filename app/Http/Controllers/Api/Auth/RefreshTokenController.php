<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\RefreshToken;
use App\Services\RoleService;
use App\Services\UipJwtService;
use App\Services\UserSessionService;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** يطابق RefreshTokenController::submit() + AuthService::refreshApiTokens() القديمين (rotation: توكن واحد الاستخدام + reuse detection). */
class RefreshTokenController extends Controller
{
    public function __construct(private RoleService $roles, private UserSessionService $sessions)
    {
    }

    public function submit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'refresh_token' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        $raw = $request->input('refresh_token');
        $hash = hash('sha256', $raw);

        $row = RefreshToken::where('token_hash', $hash)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$row) {
            // Reuse detection: لو التوكن ده كان صادر فعلاً بس اتلغى قبل كده
            // (rotated أو logout)، ده دليل إنه ممكن يكون سُرق — نلغي كل
            // refresh tokens المستخدم ده ونجبره يعمل login تاني من الصفر.
            $stale = RefreshToken::where('token_hash', $hash)->whereNotNull('revoked_at')->first();
            if ($stale) {
                RefreshToken::where('user_id', $stale->user_id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                $this->sessions->endAllForUser((int) $stale->user_id, 'token_reuse');
                SecurityLog::write('Refresh token reuse detected — revoking all sessions', ['user_id' => $stale->user_id]);
            }

            return $this->apiError('This refresh token is invalid, expired, or has already been used.', null, 401);
        }

        // الجلسة اللي الـ refresh token ده تبعها: لو اتلغت من بورتال الأمان
        // نرفض الـ refresh فالمستخدم يتطرد فعلًا.
        $session = $this->sessions->findByRefreshHash($hash);
        if ($session && !$session->is_active) {
            $row->revoked_at = now();
            $row->save();
            return $this->apiError('This session was ended. Please log in again.', null, 401);
        }

        $role = $this->roles->primaryRoleFor($row->user_id);
        $tokens = UipJwtService::issueTokenPair($row->user_id, $role, $session ? (int) $session->id : null);

        // إلغاء القديم وربطه بالجديد (rotation chain)
        $newest = RefreshToken::where('user_id', $row->user_id)->latest('id')->first();
        $row->revoked_at = now();
        $row->replaced_by_id = $newest?->id;
        $row->save();

        return $this->apiSuccess($tokens, 'Token refreshed successfully.');
    }
}
