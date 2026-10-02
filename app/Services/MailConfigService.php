<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * يطبّق إعدادات البريد المحفوظة من صفحة الأدمن (جدول settings، مفاتيح mail_*)
 * على config('mail') وقت الإرسال، فالأدمن يغيّر المزوّد (SMTP / Brevo API)
 * من الواجهة من غير ما يدخل على الـ .env أو الكود.
 *
 * لو مفيش mail_driver محفوظ في الـ DB → بيتسيب الـ .env شغال زي ما هو.
 * الأسرار (باسورد SMTP، مفتاح Brevo) بتتخزن مشفّرة بـ APP_KEY.
 */
class MailConfigService
{
    public const DRIVERS = ['smtp', 'brevo'];

    public static function encryptSecret(string $plain): string
    {
        return Crypt::encryptString($plain);
    }

    /** بيرجّع القيمة الأصلية، ولو القيمة قديمة ومحفوظة plaintext بيرجّعها زي ما هي. */
    public static function decryptSecret(?string $stored): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }
        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return $stored;
        }
    }

    public function apply(): void
    {
        try {
            $rows = DB::table('settings')
                ->where('scope', 'global')
                ->whereNull('user_id')
                ->where('key', 'like', 'mail_%')
                ->pluck('value', 'key')
                ->all();
        } catch (Throwable $e) {
            Log::warning('MailConfigService: could not load mail settings, using .env', ['error' => $e->getMessage()]);
            return;
        }

        $driver = $rows['mail_driver'] ?? '';
        if (in_array($driver, self::DRIVERS, true)) {
            config(['mail.default' => $driver]);

            if ($driver === 'brevo') {
                config(['mail.mailers.brevo.key' => self::decryptSecret($rows['mail_brevo_api_key'] ?? '') ?: (string) env('BREVO_API_KEY', '')]);
            } else {
                $encryption = $rows['mail_encryption'] ?? 'tls';
                $password = self::decryptSecret($rows['mail_password'] ?? '');

                config([
                    'mail.mailers.smtp.url'      => null,
                    'mail.mailers.smtp.host'     => ($rows['mail_host'] ?? '') ?: config('mail.mailers.smtp.host'),
                    'mail.mailers.smtp.port'     => (int) (($rows['mail_port'] ?? '') ?: config('mail.mailers.smtp.port')),
                    'mail.mailers.smtp.username' => ($rows['mail_username'] ?? '') ?: config('mail.mailers.smtp.username'),
                    'mail.mailers.smtp.password' => $password !== '' ? $password : config('mail.mailers.smtp.password'),
                    // ssl = implicit TLS (465)، tls/بدون = STARTTLS لو السيرفر بيدعمه
                    'mail.mailers.smtp.scheme'   => $encryption === 'ssl' ? 'smtps' : 'smtp',
                    // يفشل بسرعة (10 ثواني) بدل ما يستنى لحد max_execution_time
                    'mail.mailers.smtp.timeout'  => 10,
                ]);
            }
        }

        if (($rows['mail_from_address'] ?? '') !== '') {
            config(['mail.from.address' => $rows['mail_from_address']]);
        }
        if (($rows['mail_from_name'] ?? '') !== '') {
            config(['mail.from.name' => $rows['mail_from_name']]);
        }
        config(['mail.reply_to_address' => ($rows['mail_reply_to'] ?? '') ?: null]);

        // يمسح أي mailer متخزن قبل كده عشان الإعدادات الجديدة تتطبق
        Mail::purge();
    }
}
