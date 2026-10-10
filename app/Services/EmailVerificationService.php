<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * تأكيد ملكية الإيميل عند التسجيل بالباسورد.
 *
 * الحساب بيتعمل بحالة `pending` ومبيقدرش يدخل لحد ما:
 *   - صاحب الإيميل يضغط اللينك اللي بيوصله على إيميله (VerifyEmailController)، أو
 *   - الأدمن يفعّل الحساب بنفسه (Admin → Users → Activate)، أو
 *   - صاحب الإيميل يثبت ملكيته بجوجل أو بـ "نسيت كلمة السر".
 * ده بيمنع حد يسجّل بإيميل شخص تاني ويستخدم الحساب.
 */
class EmailVerificationService
{
    public const TTL_HOURS = 24;
    /** أقل فترة بين إيميلين تأكيد لنفس الحساب (ثواني) — يمنع إغراق صندوق حد بالرسائل. */
    public const RESEND_COOLDOWN = 60;

    public function __construct(private MailService $mail)
    {
    }

    /** هل الحساب محتاج تأكيد إيميل/تفعيل أدمن قبل ما يدخل؟ */
    public static function blocksLogin(object $user): bool
    {
        return ($user->status ?? null) === 'pending' && empty($user->email_verified_at);
    }

    /** يلغي أي لينكات تأكيد قديمة ويبعت لينك جديد. يرجع true لو الإيميل اتبعت فعلًا. */
    public function issueAndSend(User $user): bool
    {
        DB::table('email_verification_tokens')
            ->where('user_id', $user->id)->where('purpose', 'signup')->whereNull('used_at')
            ->update(['used_at' => now()]);

        $plain = bin2hex(random_bytes(32));
        DB::table('email_verification_tokens')->insert([
            'user_id'    => $user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addHours(self::TTL_HOURS),
            'purpose'    => 'signup',
            'created_at' => now(),
        ]);

        $base = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');
        $url = $base . '/auth/verify-email?token=' . $plain;

        $sent = $this->mail->sendEmailVerification(
            (string) $user->email,
            (string) ($user->full_name ?? ''),
            $url,
            (string) ($user->preferred_language ?? 'ar')
        );
        if (!$sent) {
            Log::error('Verification email failed', ['user_id' => $user->id, 'error' => $this->mail->getLastError()]);
        }

        return $sent;
    }

    /** آخر مرة اتبعت فيها لينك لحساب ده (للـ cooldown). */
    public function lastSentAt(int $userId): ?\Illuminate\Support\Carbon
    {
        $at = DB::table('email_verification_tokens')
            ->where('user_id', $userId)->where('purpose', 'signup')->max('created_at');

        return $at ? \Illuminate\Support\Carbon::parse($at) : null;
    }

    /** يثبّت إن الإيميل ملك صاحبه ويفعّل الحساب لو كان pending. */
    public function markVerified(int $userId): void
    {
        // مكتوبة بدون دوال SQL خاصة بقاعدة بيانات بعينها (تشتغل على MySQL و SQLite الاختبارات).
        DB::table('users')->where('id', $userId)->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
        DB::table('users')->where('id', $userId)->where('status', 'pending')->update(['status' => 'active']);
        DB::table('users')->where('id', $userId)->update(['updated_at' => now()]);
        DB::table('email_verification_tokens')
            ->where('user_id', $userId)->where('purpose', 'signup')->whereNull('used_at')
            ->update(['used_at' => now()]);
    }
}
