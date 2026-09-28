<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * يطابق ForgotPasswordController::submit() + AuthService::createPasswordResetToken() القديمين.
 * ⚠️ عن قصد الرد دايمًا بنجاح بغض النظر هل الإيميل موجود ولا لأ (منع user enumeration).
 *
 * TODO: MailService::sendPasswordReset() الحقيقي غير متصل هنا لسه — دلوقتي
 * بس بيسجل اللينك في الـ log (زي القديم في وضع التطوير) بدل ما يبعت إيميل فعلي.
 */
class ForgotPasswordController extends Controller
{
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

            $resetUrl = rtrim(config('app.url'), '/') . '/auth/reset-password?token=' . $plainToken;
            Log::info('Password reset link', ['email' => $email, 'link' => $resetUrl]);

            // TODO: استبدل السطر ده بإرسال إيميل حقيقي عبر Mail::to($user->email)->send(...)
        }

        return response()->json([
            'success' => true,
            'message' => 'If an account with that email exists, a reset link has been sent.',
        ]);
    }
}
