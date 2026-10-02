<?php

namespace App\Services;

use App\Mail\GenericMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * كانت دي فجوة متعمدة موثقة (README.md، بند "متعمّد إنه لسه على النظام
 * القديم" رقم 4): كل ميثود هنا كانت بتعمل Log::info() بس وترجع true —
 * من غير ما تبعت أي إيميل حقيقي. ده سبب إن دعوات (عضو هيئة تدريس/طالب/
 * فريق شركة...الخ) كانت بتتسجل في السيستم وبتغيّر الباسورد، بس مفيش
 * إيميل بيوصل فعليًا للمستلم.
 *
 * الإصلاح: كل الميثودز بقت بتبعت فعليًا عبر Laravel Mail (GenericMail
 * Mailable + resources/views/emails/generic.blade.php)، باستخدام الـ
 * mailer المضبوط في config/mail.php (يعتمد على MAIL_MAILER في .env —
 * لازم تتظبط SMTP حقيقية في .env عشان الإرسال يشتغل فعليًا؛ لو
 * MAIL_MAILER=log (الافتراضي في Laravel لو الـ .env فاضي) هيفضل بيسجل في
 * storage/logs/laravel.log بس زي الأول، من غير error — ده سلوك Laravel
 * نفسه مش حاجة إضافية هنا).
 *
 * كل الميثودز اللي نتيجتها بتتحول لرسالة نجاح/فشل فعلية للمستخدم (دعوات،
 * إعادة تعيين باسورد، "Send Test Email to Myself") بترجع true/false حسب
 * نجاح الإرسال الفعلي دلوقتي (مش true دايمًا زي الاستَب القديم) — عشان
 * resendInvite() وشبهها يقدروا يبلّغوا المستخدم صح لو الإرسال فشل.
 *
 * الميثودز اللي كانت موثّقة إنها "لازم تفضل ترجع true دايمًا عشان الخدمة
 * المستدعية (ReportScheduler/DataExport/AccountLockout) متتوقفش" باقية
 * على نفس العقد ده — بتحاول تبعت فعليًا، وبتسجّل أي فشل في الـ log، لكن
 * برضه بترجع true عشان معملياتها الأساسية (تسجيل، تصدير، قفل حساب)
 * تفضل شغالة حتى لو SMTP واقع مؤقتًا.
 */
class MailService
{
    /** آخر خطأ إرسال (بيظهر للأدمن في "Send Test Email"). */
    private ?string $lastError = null;

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param array<int, array{label: string, value: string}> $meta صفوف إضافية (باسورد مؤقت...الخ)
     */
    private function deliver(
        string $toEmail,
        string $subject,
        string $heading,
        array $lines,
        ?array $button,
        string $locale,
        array $meta,
        string $logContext
    ): bool {
        $this->lastError = null;

        try {
            app(MailConfigService::class)->apply();
            Mail::to($toEmail)->send(new GenericMail($subject, $heading, $lines, $button, $locale, $meta));

            return true;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::error("{$logContext}: failed to send email", [
                'email' => $toEmail,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function loginUrl(): string
    {
        $base = rtrim((string) (env('FRONTEND_URL') ?: env('APP_URL', 'http://localhost')), '/');

        return "{$base}/login";
    }

    public function sendStudentInvite(string $email, string $fullName, string $universityName, string $tempPassword, string $locale = 'ar'): bool
    {
        $ar = $locale === 'ar';

        return $this->deliver(
            $email,
            $ar ? "دعوة للانضمام إلى {$universityName}" : "You're invited to join {$universityName}",
            $ar ? "أهلاً {$fullName}" : "Hi {$fullName}",
            [
                $ar
                    ? "تمت دعوتك للانضمام كطالب في {$universityName}. استخدم بيانات الدخول المؤقتة أدناه لتسجيل الدخول ثم غيّر كلمة السر."
                    : "You've been invited to join {$universityName} as a student. Use the temporary login details below to sign in, then change your password.",
            ],
            ['label' => $ar ? 'تسجيل الدخول' : 'Log in', 'url' => $this->loginUrl()],
            $locale,
            [
                ['label' => $ar ? 'البريد الإلكتروني' : 'Email', 'value' => $email],
                ['label' => $ar ? 'كلمة السر المؤقتة' : 'Temporary password', 'value' => $tempPassword],
            ],
            'sendStudentInvite'
        );
    }

    public function sendSupervisorInvite(string $toEmail, string $fullName, string $universityName, string $tempPassword, string $locale = 'ar'): bool
    {
        $ar = $locale === 'ar';

        return $this->deliver(
            $toEmail,
            $ar ? "دعوة للانضمام كمشرف في {$universityName}" : "You're invited as a supervisor at {$universityName}",
            $ar ? "أهلاً {$fullName}" : "Hi {$fullName}",
            [
                $ar
                    ? "تمت دعوتك للانضمام كمشرف في {$universityName}. استخدم بيانات الدخول المؤقتة أدناه لتسجيل الدخول ثم غيّر كلمة السر."
                    : "You've been invited to join {$universityName} as a supervisor. Use the temporary login details below to sign in, then change your password.",
            ],
            ['label' => $ar ? 'تسجيل الدخول' : 'Log in', 'url' => $this->loginUrl()],
            $locale,
            [
                ['label' => $ar ? 'البريد الإلكتروني' : 'Email', 'value' => $toEmail],
                ['label' => $ar ? 'كلمة السر المؤقتة' : 'Temporary password', 'value' => $tempPassword],
            ],
            'sendSupervisorInvite'
        );
    }

    public function sendAcademicStaffInvite(string $toEmail, string $fullName, string $universityName, string $tempPassword, string $locale = 'ar'): bool
    {
        $ar = $locale === 'ar';

        return $this->deliver(
            $toEmail,
            $ar ? "دعوة للانضمام كعضو هيئة تدريس في {$universityName}" : "You're invited as academic staff at {$universityName}",
            $ar ? "أهلاً {$fullName}" : "Hi {$fullName}",
            [
                $ar
                    ? "تمت دعوتك للانضمام كعضو هيئة تدريس في {$universityName}. استخدم بيانات الدخول المؤقتة أدناه لتسجيل الدخول ثم غيّر كلمة السر."
                    : "You've been invited to join {$universityName} as academic staff. Use the temporary login details below to sign in, then change your password.",
            ],
            ['label' => $ar ? 'تسجيل الدخول' : 'Log in', 'url' => $this->loginUrl()],
            $locale,
            [
                ['label' => $ar ? 'البريد الإلكتروني' : 'Email', 'value' => $toEmail],
                ['label' => $ar ? 'كلمة السر المؤقتة' : 'Temporary password', 'value' => $tempPassword],
            ],
            'sendAcademicStaffInvite'
        );
    }

    public function sendNotificationEmail(string $toEmail, string $fullName, string $title, ?string $body, ?string $linkUrl, string $locale = 'ar'): bool
    {
        $ar = $locale === 'ar';
        $lines = [$ar ? "أهلاً {$fullName}" : "Hi {$fullName}"];
        if ($body) {
            $lines[] = $body;
        }

        return $this->deliver(
            $toEmail,
            $title,
            $title,
            $lines,
            $linkUrl ? ['label' => $ar ? 'عرض التفاصيل' : 'View details', 'url' => $linkUrl] : null,
            $locale,
            [],
            'sendNotificationEmail'
        );
    }

    /**
     * تسليم تقرير مجدول — بترجع true دايمًا حتى لو الإرسال فشل (نفس العقد
     * القديم، موثّق في README) عشان ReportSchedulerService يكمل جدولة
     * الجري الجاي حتى لو SMTP واقع مؤقتًا؛ الفشل نفسه بيتسجل في الـ log.
     */
    public function sendScheduledReport(string $toEmail, string $reportLabel, string $frequency, ?string $downloadUrl): bool
    {
        $this->deliver(
            $toEmail,
            "{$reportLabel} — {$frequency}",
            $reportLabel,
            ["Your scheduled report \"{$reportLabel}\" ({$frequency}) is ready."],
            $downloadUrl ? ['label' => 'Download', 'url' => $downloadUrl] : null,
            'en',
            [],
            'sendScheduledReport'
        );

        return true;
    }

    public function sendDataExportReady(string $toEmail, string $exportTypeLabel, string $format, ?string $downloadUrl, string $locale = 'ar'): bool
    {
        $ar = $locale === 'ar';

        $this->deliver(
            $toEmail,
            $ar ? "تصدير {$exportTypeLabel} جاهز" : "Your {$exportTypeLabel} export is ready",
            $ar ? 'تصديرك جاهز' : 'Your export is ready',
            [
                $ar
                    ? "تصدير \"{$exportTypeLabel}\" بصيغة " . strtoupper($format) . ' أصبح جاهزًا للتحميل.'
                    : "Your \"{$exportTypeLabel}\" export in " . strtoupper($format) . ' format is ready to download.',
            ],
            $downloadUrl ? ['label' => $ar ? 'تحميل' : 'Download', 'url' => $downloadUrl] : null,
            $locale,
            [],
            'sendDataExportReady'
        );

        return true;
    }

    public function sendAccountLockedNotice(
        string $toEmail,
        string $fullName,
        bool $permanent,
        ?string $lockedUntil,
        string $supportEmail,
        string $supportPhone,
        string $locale = 'ar'
    ): bool {
        $ar = $locale === 'ar';

        $this->deliver(
            $toEmail,
            $ar ? 'تم قفل حسابك' : 'Your account was locked',
            $ar ? "أهلاً {$fullName}" : "Hi {$fullName}",
            [
                $permanent
                    ? ($ar ? 'تم قفل حسابك بشكل دائم لأسباب أمنية.' : 'Your account has been permanently locked for security reasons.')
                    : ($ar ? "تم قفل حسابك مؤقتًا حتى {$lockedUntil}." : "Your account is temporarily locked until {$lockedUntil}."),
                $ar
                    ? "للمساعدة تواصل معنا عبر {$supportEmail} أو {$supportPhone}."
                    : "For help, contact us at {$supportEmail} or {$supportPhone}.",
            ],
            null,
            $locale,
            [],
            'sendAccountLockedNotice'
        );

        return true;
    }

    /**
     * "Send Test Email to Myself" — على عكس الاستَب القديم، دي لازم ترجع
     * نتيجة الإرسال الفعلية (مش true دايمًا) لأن الغرض الوحيد منها إن
     * الأدمن يتأكد إن إعدادات SMTP شغالة فعلاً.
     */
    public function sendTestEmail(string $toEmail, string $fullName, string $locale = 'ar'): bool
    {
        $ar = $locale === 'ar';

        return $this->deliver(
            $toEmail,
            $ar ? 'رسالة اختبار' : 'Test email',
            $ar ? "أهلاً {$fullName}" : "Hi {$fullName}",
            [
                $ar
                    ? 'دي رسالة اختبار للتأكد إن إعدادات البريد الإلكتروني شغالة صح.'
                    : 'This is a test email to confirm your mail settings are working correctly.',
            ],
            null,
            $locale,
            [],
            'sendTestEmail'
        );
    }

}
