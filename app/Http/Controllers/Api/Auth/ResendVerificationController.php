<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * POST /auth/resend-verification { email } — يبعت لينك تأكيد جديد لحساب pending.
 * الرد دايمًا نفس الرسالة (منع user enumeration)، ومحدود بـ throttle + cooldown لكل حساب.
 */
class ResendVerificationController extends Controller
{
    public function __construct(private EmailVerificationService $verification)
    {
    }

    public function submit(Request $request)
    {
        $v = Validator::make($request->all(), ['email' => 'required|email']);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
        }

        $user = User::whereRaw('LOWER(email) = ?', [strtolower((string) $request->input('email'))])->first();
        if ($user && EmailVerificationService::blocksLogin($user)) {
            $last = $this->verification->lastSentAt((int) $user->id);
            if (!$last || $last->diffInSeconds(now()) >= EmailVerificationService::RESEND_COOLDOWN) {
                $this->verification->issueAndSend($user);
            }
        }

        $ar = $request->header('X-Locale', 'en') === 'ar';
        return response()->json([
            'success' => true,
            'message' => $ar
                ? 'لو فيه حساب بهذا البريد محتاج تأكيد، بعتنا له رسالة جديدة.'
                : 'If an account with that email needs confirmation, we sent a new message.',
        ]);
    }
}
