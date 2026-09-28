<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * يطابق جدول `departments` القديم بالظبط (migration 100 + is_public لاحقًا).
 * تابع لكلية واحدة (faculty_id)، وبالتبعية لجامعة واحدة.
 */
class Department extends Model
{
    protected $table = 'departments';

    protected $fillable = [
        'faculty_id', 'name_ar', 'name_en', 'slug', 'description', 'status', 'is_public',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function name(string $locale = 'en'): string
    {
        return $locale === 'ar'
            ? ($this->name_ar ?: $this->name_en)
            : ($this->name_en ?: $this->name_ar);
    }
}
