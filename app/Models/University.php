<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `universities` القديم بالظبط (migrations 004/094/137).
 * كل حساب جامعة = صف `users` (role=university) + صف `universities` مرتبط
 * بيه عبر user_id.
 */
class University extends Model
{
    protected $table = 'universities';

    protected $fillable = [
        'user_id', 'official_name_ar', 'official_name_en', 'slug', 'country', 'city',
        'website', 'logo_path', 'verification_status', 'verified_at', 'verified_by',
        'is_public',
        // Certificate branding (2026_10_25_100000): signature, stamp, dean signature + dean details
        'signature_path', 'stamp_path', 'dean_signature_path',
        'dean_name_en', 'dean_name_ar', 'dean_title_en', 'dean_title_ar',
        // Automatic Re-verification (094)
        'verification_period_days', 'verification_expires_at',
        'verification_expiry_notified_at', 'auto_reverify_enabled',
    ];

    protected $casts = [
        'verified_at'                      => 'datetime',
        'is_public'                        => 'boolean',
        'verification_expires_at'          => 'datetime',
        'verification_expiry_notified_at'  => 'datetime',
        'auto_reverify_enabled'            => 'boolean',
    ];

    /** branding kind (as used in the URL) => column that holds its path. */
    public const BRANDING_KINDS = [
        'signature'      => 'signature_path',
        'stamp'          => 'stamp_path',
        'dean_signature' => 'dean_signature_path',
    ];

    /**
     * Absolute URLs of the branding images (null when not uploaded), keyed `<kind>_url`,
     * plus the dean's name/title. This is the shape the certificate page and the uploader read.
     *
     * @return array<string,?string>
     */
    public function brandingUrls(): array
    {
        $out = [];
        foreach (self::BRANDING_KINDS as $kind => $column) {
            $path = $this->{$column};
            $out[$kind . '_url'] = $path ? asset(ltrim($path, '/')) : null;
        }
        foreach (['dean_name_en', 'dean_name_ar', 'dean_title_en', 'dean_title_ar'] as $field) {
            $out[$field] = $this->{$field};
        }
        return $out;
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    public function name(string $locale = 'en'): string
    {
        return $locale === 'ar'
            ? ($this->official_name_ar ?: $this->official_name_en)
            : ($this->official_name_en ?: $this->official_name_ar);
    }
}
