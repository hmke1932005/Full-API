<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Exam & Assessment System — Round 3 (Attempts). محاولة طالب واحدة على
 * امتحان واحد. راجع docblock migration 2026_08_28_170000 لمعنى كل عمود
 * ومنطق status/expires_at. isExpired()/remainingSeconds() هي اللي
 * ExamAttemptService::enforceTimer() بيتكئ عليها — الحساب دايمًا بالساعة
 * السيرفر (now())، مفيش أي وقت جاي من العميل بيتصدق.
 */
class ExamAttempt extends Model
{
    protected $table = 'exam_attempts';

    protected $fillable = [
        'exam_id', 'student_id', 'attempt_number', 'status',
        'started_at', 'last_activity_at', 'expires_at', 'submitted_at',
        'auto_submitted', 'violations_count', 'score', 'percentage',
        'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    protected $casts = [
        'started_at'       => 'datetime',
        'last_activity_at' => 'datetime',
        'expires_at'       => 'datetime',
        'submitted_at'     => 'datetime',
        'cancelled_at'     => 'datetime',
        'auto_submitted'   => 'boolean',
        'score'            => 'decimal:2',
        'percentage'       => 'decimal:2',
    ];

    /** الحالات اللي المحاولة لسه "شغالة" فيها — الطالب لسه ممكن يحفظ إجابات. */
    public const ACTIVE_STATUSES = ['in_progress'];

    /** الحالات اللي بتعتبر "خلصت" فعليًا (submitted بأي شكل) — يقصون المحاولة من عد "attempts remaining". */
    public const FINISHED_STATUSES = ['submitted', 'auto_submitted', 'expired', 'cancelled', 'grading', 'graded'];

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function answers()
    {
        return $this->hasMany(ExamAnswer::class);
    }

    public function grades()
    {
        return $this->hasMany(ExamGrade::class, 'exam_attempt_id');
    }

    public function securityEvents()
    {
        return $this->hasMany(ExamSecurityEvent::class, 'exam_attempt_id')->orderBy('occurred_at');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->greaterThanOrEqualTo($this->expires_at);
    }

    /** ثواني متبقية (0 لو خلصت أو الوقت عدى) — دايمًا محسوبة من ساعة السيرفر. */
    public function remainingSeconds(): int
    {
        if (!$this->isActive() || $this->expires_at === null) {
            return 0;
        }
        return max(0, now()->diffInSeconds($this->expires_at, false));
    }
}
