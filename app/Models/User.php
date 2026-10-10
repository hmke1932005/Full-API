<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * يطابق جدول `users` القديم بالظبط (نفس الأعمدة، بدون تعديل على القاعدة).
 * password_hash (مش password) عشان كده معمول override لـ getAuthPassword().
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $table = 'users';

    /**
     * الحذف soft delete (deleted_at) بس عمود email عليه unique، فالصف المحذوف كان بيفضل ماسك الإيميل:
     * التسجيل/الدعوة بنفس الإيميل بعد الحذف كانت بتقع بـ 500 (duplicate key) لأن كل البحث بيتجاهل المحذوفين.
     * قبل أي إنشاء حساب جديد بنحرّر الإيميل من أي صف محذوف (بنغيّره لقيمة tombstone؛ الصف نفسه وسجلاته بتفضل).
     */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $email = trim((string) $user->email);
            if ($email === '') {
                return;
            }
            $trashed = \Illuminate\Support\Facades\DB::table('users')
                ->whereNotNull('deleted_at')
                ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                ->get(['id', 'email']);
            foreach ($trashed as $row) {
                \Illuminate\Support\Facades\DB::table('users')->where('id', $row->id)->update([
                    'email' => substr('deleted-' . $row->id . '-' . time() . '-' . $row->email, 0, 190),
                ]);
            }
        });
    }

    protected $fillable = [
        'uuid', 'full_name', 'name_ar', 'name_en', 'email', 'phone', 'password_hash', 'avatar_path',
        'preferred_language', 'theme_preference', 'status', 'email_verified_at',
        'last_login_at', 'last_login_ip', 'two_factor_enabled', 'remember_token',
        // 2FA enrollment — دول كانوا ناقصين من
        // fillable فعليًا، يعني أي fill() سابق ليهم كان بيتجاهَل بصمت لحد
        // دلوقتي (مفيش كولر بينادي عليهم قبل كده). اتضافوا هنا مع
        // UserRepository::enableTwoFactor()/disableTwoFactor().
        'two_factor_secret', 'two_factor_confirmed_at', 'two_factor_recovery_codes',
        'mfa_grace_started_at',
    ];

    protected $hidden = [
        'password_hash', 'remember_token',
    ];

    protected $casts = [
        'email_verified_at'          => 'datetime',
        'last_login_at'              => 'datetime',
        'two_factor_enabled'         => 'boolean',
        'two_factor_confirmed_at'    => 'datetime',
        'two_factor_recovery_codes'  => 'array',
        'locked_until'               => 'datetime',
        'lock_permanent'             => 'boolean',
        'locked_at'                  => 'datetime',
        'mfa_grace_started_at'       => 'datetime',
    ];

    /** لارافيل بيدور على password افتراضيًا؛ عندنا العمود اسمه password_hash. */
    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    /** الاسم حسب اللغة (ar/en) مع fallback للتانية ثم full_name القديم. */
    public function nameFor(string $locale = 'ar'): string
    {
        return \App\Support\BilingualName::pick($this->name_ar, $this->name_en, $locale, (string) $this->full_name);
    }

    public function refreshTokens()
    {
        return $this->hasMany(RefreshToken::class);
    }
}
