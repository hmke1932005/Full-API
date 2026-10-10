<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** يطابق VerifyEmailController::handle() القديم بالظبط. */
class VerifyEmailController extends Controller
{
    public function handle(Request $request)
    {
        $token = (string) $request->query('token', '');

        if ($token === '') {
            return $this->apiError('This verification link is invalid or has expired.', null, 422);
        }

        $hash = hash('sha256', $token);

        $row = DB::table('email_verification_tokens')
            ->where('token_hash', $hash)
            ->where('purpose', 'signup')
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$row) {
            return $this->apiError('This verification link is invalid or has expired.', null, 422);
        }

        // يثبّت الإيميل ويفعّل الحساب (pending -> active)، ويقفل باقي لينكات التأكيد.
        app(\App\Services\EmailVerificationService::class)->markVerified((int) $row->user_id);
        DB::table('email_verification_tokens')->where('id', $row->id)->update(['used_at' => now()]);

        return $this->apiSuccess(null, 'Email verified successfully.');
    }
}
