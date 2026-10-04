<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamStudentOverride;
use App\Repositories\ExamAttemptRepository;
use App\Repositories\ExamTargetRepository;
use App\Repositories\StudentRepository;
use Carbon\Carbon;

/**
 * صلاحيات المدرس على محاولات الطلاب: إلغاء محاولة، والسماح بإعادة الامتحان.
 * ملكية الامتحان بتتفحص في الـ controller (ExamGradingApiController) قبل أي
 * ميثود هنا، فالخدمة دي بتفترض إن المدرس مالك الامتحان.
 *
 * - cancelAttempt(): status=cancelled + السبب + مين ألغى. لو المحاولة كانت شغالة
 *   الطالب بيتفصل منها (أي حفظ/تسليم بيترفض لأن المحاولة مبقتش in_progress،
 *   وactive-attempt بيرجّع false فقفل الامتحان بيتفك). بتفضل في السجل
 *   وبتتحسب ضمن المحاولات المستخدمة إلا لو allowRetake=true.
 * - grantRetake(): بيزوّد extra_attempts للطالب ده على الامتحان ده، واختياريًا
 *   بيفتحله نافذة وصول خاصة (available_until) لو الامتحان خلص وقته.
 * - revokeRetake(): بيسحب المحاولات الإضافية اللي لسه ماتستخدمتش.
 * كل فعل بيتسجل في audit log وبيوصل للطالب كإشعار.
 */
class ExamAttemptManagementService
{
    public const MAX_EXTRA_ATTEMPTS = 10;

    public function __construct(
        private ExamAttemptRepository $attempts,
        private ExamTargetRepository $targets,
        private StudentRepository $students,
        private NotificationService $notifications,
        private AuditLogService $auditLog
    ) {
    }

    /**
     * @throws \InvalidArgumentException
     * @return array{attempt: ExamAttempt, override: ?ExamStudentOverride}
     */
    public function cancelAttempt(
        Exam $exam,
        ExamAttempt $attempt,
        $staffUserId,
        ?string $reason = null,
        bool $allowRetake = false,
        ?Carbon $availableUntil = null
    ): array {
        if ($attempt->status === 'cancelled') {
            throw new \InvalidArgumentException('This attempt is already cancelled.');
        }

        $reason = $reason !== null ? trim($reason) : null;
        $reason = $reason === '' ? null : $reason;
        $oldStatus = $attempt->status;

        $attempt->status = 'cancelled';
        $attempt->cancelled_at = now();
        $attempt->cancelled_by = $staffUserId;
        $attempt->cancel_reason = $reason;
        $attempt->save();

        $this->auditLog->record(
            $staffUserId,
            'exam.attempt_cancelled',
            'ExamAttempt',
            $attempt->id,
            ['status' => $oldStatus],
            ['status' => 'cancelled', 'reason' => $reason, 'allow_retake' => $allowRetake, 'exam_id' => $exam->id, 'student_id' => $attempt->student_id]
        );

        $override = null;
        if ($allowRetake) {
            $override = $this->grantRetake($exam, $attempt->student_id, 1, $availableUntil, $staffUserId, $reason, false);
        }

        $this->notifyStudent(
            $attempt->student_id,
            'exam_attempt_cancelled',
            'Exam attempt cancelled: ' . $exam->title,
            'Your attempt on "' . $exam->title . '" was cancelled by your instructor.'
                . ($reason ? ' Reason: ' . $reason : '')
                . ($allowRetake ? ' You have been allowed to retake the exam.' : ''),
            $allowRetake ? 'high' : 'normal'
        );

        return ['attempt' => $attempt, 'override' => $override];
    }

    /**
     * يسمح للطالب ده بمحاولات إضافية على الامتحان (إعادة). بتتراكم فوق اللي
     * اتمنح قبل كده، بحد أقصى MAX_EXTRA_ATTEMPTS.
     *
     * @throws \InvalidArgumentException
     */
    public function grantRetake(
        Exam $exam,
        $studentId,
        int $extraAttempts = 1,
        ?Carbon $availableUntil = null,
        $staffUserId = null,
        ?string $reason = null,
        bool $notify = true
    ): ExamStudentOverride {
        if ($extraAttempts < 1 || $extraAttempts > self::MAX_EXTRA_ATTEMPTS) {
            throw new \InvalidArgumentException('Extra attempts must be between 1 and ' . self::MAX_EXTRA_ATTEMPTS . '.');
        }

        $student = $this->students->find($studentId);
        if (!$student || (int) $student->university_id !== (int) $exam->university_id) {
            throw new \InvalidArgumentException('Student not found.');
        }
        if (!$this->targets->studentIsEligibleForExam($exam->id, $student->id)) {
            throw new \InvalidArgumentException('This student is not eligible for this exam.');
        }
        if ($availableUntil !== null && $availableUntil->lessThanOrEqualTo(now())) {
            throw new \InvalidArgumentException('The retake deadline must be in the future.');
        }

        $existing = $this->attempts->overrideFor($exam->id, $student->id);
        $oldExtra = $existing ? (int) $existing->extra_attempts : 0;
        $newExtra = min(self::MAX_EXTRA_ATTEMPTS, $oldExtra + $extraAttempts);

        $data = [
            'extra_attempts' => $newExtra,
            'granted_by'     => $staffUserId,
        ];
        if ($availableUntil !== null) {
            $data['available_until'] = $availableUntil;
        }
        if ($reason !== null && trim($reason) !== '') {
            $data['reason'] = trim($reason);
        }
        $override = $this->attempts->saveOverride($exam->id, $student->id, $data);

        $this->auditLog->record(
            $staffUserId,
            'exam.retake_granted',
            'Exam',
            $exam->id,
            ['extra_attempts' => $oldExtra],
            ['student_id' => $student->id, 'extra_attempts' => $newExtra, 'available_until' => $availableUntil?->toDateTimeString(), 'reason' => $reason]
        );

        if ($notify) {
            $this->notifyStudent(
                $student->id,
                'exam_retake_granted',
                'You can retake: ' . $exam->title,
                'Your instructor allowed you another attempt on "' . $exam->title . '".'
                    . ($availableUntil ? ' Available until ' . $availableUntil->toDayDateTimeString() . '.' : ''),
                'high'
            );
        }

        return $override;
    }

    /**
     * يسحب المحاولات الإضافية اللي لسه ماتستخدمتش (اللي اتستخدمت فعلًا مش بتتأثر).
     *
     * @throws \InvalidArgumentException
     */
    public function revokeRetake(Exam $exam, $studentId, $staffUserId = null): ExamStudentOverride
    {
        $override = $this->attempts->overrideFor($exam->id, $studentId);
        if (!$override || (int) $override->extra_attempts < 1) {
            throw new \InvalidArgumentException('This student has no extra attempts to revoke.');
        }

        $used = count($this->attempts->forStudentAndExam($studentId, $exam->id));
        $unused = ((int) $exam->max_attempts + (int) $override->extra_attempts) - $used;
        if ($unused < 1) {
            throw new \InvalidArgumentException('All extra attempts were already used.');
        }

        $old = (int) $override->extra_attempts;
        $override->extra_attempts = max(0, $old - $unused);
        $override->save();

        $this->auditLog->record(
            $staffUserId,
            'exam.retake_revoked',
            'Exam',
            $exam->id,
            ['extra_attempts' => $old],
            ['student_id' => (int) $studentId, 'extra_attempts' => (int) $override->extra_attempts]
        );

        $this->notifyStudent(
            $studentId,
            'exam_retake_revoked',
            'Retake withdrawn: ' . $exam->title,
            'Your instructor withdrew the extra attempt on "' . $exam->title . '".',
            'normal'
        );

        return $override;
    }

    private function notifyStudent($studentId, string $type, string $title, string $body, string $priority): void
    {
        $student = $this->students->find($studentId);
        if ($student) {
            $this->notifications->notify($student->user_id, $type, $title, $body, null, $priority);
        }
    }
}
