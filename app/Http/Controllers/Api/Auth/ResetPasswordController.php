<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\PasswordPolicyService;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** يطابق ResetPasswordController::submit() + AuthService::resetPassword() القديمين، تعقيد كلمة السر شغال بالكامل. */
class ResetPasswordController extends Controller
{
    public function __construct(private PasswordPolicyService $passwordPolicy)
    {
    }

    public function submit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token'    => 'required',
            'password' => 'required|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $data = $validator->validated();
        $hash = hash('sha256', $data['token']);

        $row = DB::table('password_reset_tokens')
            ->where('token_hash', $hash)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$row) {
            return response()->json([
                'success' => false,
                'message' => 'This reset link is invalid or has expired.',
            ], 422);
        }

        try {
            $this->passwordPolicy->assertValid($data['password']);
            $this->passwordPolicy->assertNotReused($row->user_id, $data['password']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $newHash = password_hash($data['password'], PASSWORD_BCRYPT);

        DB::table('users')->where('id', $row->user_id)->update([
            'password_hash' => $newHash,
            'updated_at'    => now(),
        ]);

        DB::table('password_reset_tokens')->where('id', $row->id)->update(['used_at' => now()]);
        // اللينك وصل لإيميل الحساب -> ده إثبات ملكية الإيميل (يفعّل حساب لسه pending).
        app(\App\Services\EmailVerificationService::class)->markVerified((int) $row->user_id);
        $this->passwordPolicy->recordPasswordChange($row->user_id, $newHash);

        SecurityLog::write('Password reset completed', ['user_id' => $row->user_id]);

        return response()->json(['success' => true, 'redirect' => '/auth/login']);
    }
}
