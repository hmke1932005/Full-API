<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Mailable عام واحد لكل إيميلات المشروع (دعوات، إعادة تعيين باسورد،
 * إشعارات، تقارير...). بديل حقيقي لـ MailService القديمة اللي كانت بتعمل
 * Log::info() بس (فجوة موثقة في README.md — بند 4 من "متعمّد إنه لسه على
 * النظام القديم"). كل ميثود في MailService بتبني subject/heading/lines/
 * button وتبعتها هنا بدل ما تسجّلها.
 *
 * $lines: مصفوفة أسطر نص عادي (كل سطر <p> منفصل في القالب) — من غير HTML
 * عشان مفيش مدخل XSS من بيانات المستخدم (اسم، إيميل...الخ) يوصل هنا.
 * $button: ['label' => string, 'url' => string]|null.
 *
 * ملحوظة مهمة: الاسم $mailLocale (مش $locale) عن قصد — Illuminate\Mail\
 * Mailable الأصلية معرّفة فيها property اسمها $locale (بتستخدمها
 * Mailable::locale()/withLocale() لتحديد لغة الرندر)، فلو عملنا override
 * ليها بـ type مختلف (string بدل نوعها الأصلي) PHP بيرمي Fatal Error:
 * "Type of App\Mail\GenericMail::$locale must not be defined (as in class
 * Illuminate\Mail\Mailable)". استخدمنا اسم مختلف تمامًا عشان نتجنب أي
 * تعارض مع أي property تانية في الكلاس الأب دلوقتي أو في نسخة Laravel
 * جاية.
 */
class GenericMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $mailSubject,
        public string $heading,
        public array $lines,
        public ?array $button = null,
        public string $mailLocale = 'ar',
        public ?array $meta = null, // صفوف label/value إضافية (زي الباسورد المؤقت)
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.generic',
            with: [
                'heading'        => $this->heading,
                'lines'          => $this->lines,
                'button'         => $this->button,
                'locale'         => $this->mailLocale,
                'meta'           => $this->meta ?? [],
                // اسم المنصة الظاهر في هيدر الإيميل — بياخده من APP_NAME في
                // .env (نفس الاسم اللي بيظهر في باقي الموقع)، مش hardcoded
                // "Laravel". غيّر APP_NAME في .env لو عايز اسم مختلف.
                'appName'        => config('app.name', 'UIP'),
                // لوجو اختياري (رابط https لصورة PNG/JPG صغيرة، ~120x32px
                // تقريبًا) — لو معرّفش MAIL_LOGO_URL في .env، الهيدر بيعرض
                // حرف أول اسم المنصة جوه مربع بدل اللوجو.
                'logoUrl'        => env('MAIL_LOGO_URL'),
                // لون العلامة التجارية (هيدر + زرار) — قابل للتخصيص من
                // .env (MAIL_BRAND_COLOR) من غير تعديل كود. لازم يبقى Hex
                // صالح (#1e3a8a مثلًا).
                'brandColor'     => env('MAIL_BRAND_COLOR', '#1e3a8a'),
                'brandColorDark' => env('MAIL_BRAND_COLOR_DARK', '#0f2557'),
            ],
        );
    }
}
