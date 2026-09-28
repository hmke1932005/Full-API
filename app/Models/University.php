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
