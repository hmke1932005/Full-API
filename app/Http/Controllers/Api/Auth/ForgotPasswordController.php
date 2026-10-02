<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * يطابق ForgotPasswordController::submit() + AuthService::createPasswordResetToken() القديمين.
 * ⚠️ عن قصد الرد دايمًا بنجاح بغض النظر هل الإيميل موجود ولا لأ (منع user enumeration).
 *
 * بيبعت إيميل إعادة التعيين فعليًا عبر MailService::sendPasswordReset()،
 * وأي فشل في الإرسال بيتسجل في الـ log بس (الرد للمستخدم ثابت).
 */
class ForgotPasswordController extends Controller
{
    public function __construct(private MailService $mail)
    {
    }

    public function submit(Request $request)
    {
        $validator = Validator::make($request->all(), ['email' => 'required|email']);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $email = $request->input('email');
        $user = User::where('email', $email)->first();

        if ($user) {
            $plainToken = bin2hex(random_bytes(32));

            DB::table('password_reset_tokens')->insert([
                'user_id'    => $user->id,
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => now()->addHour(),
                'created_at' => now(),
            ]);

            $base = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');
            $resetUrl = $base . '/auth/reset-password?token=' . $plainToken;

            $sent = $this->mail->sendPasswordReset(
                (string) $user->email,
                (string) ($user->full_name ?? ''),
                $resetUrl,
                (string) ($user->preferred_language ?? 'ar')
            );

            if (!$sent) {
                Log::error('Password reset email failed', ['email' => $email, 'error' => $this->mail->getLastError()]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'If an account with that email exists, a reset link has been sent.',
        ]);
    }
}
