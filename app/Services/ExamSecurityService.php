<?php

namespace App\Services;

use App\Models\ExamAttempt;
use App\Models\ExamSecurityEvent;
use App\Repositories\ExamSecurityEventRepository;

/**
 * Exam & Assessment System — Round 5 (Secure Exam Mode + Security Events،
 * Phases 13-16). سطح الخدمة اللي بيغطي: تسجيل أحداث الأمان الجاية من
 * متصفح الطالب وقت المحاولة (fullscreen/tab/copy-paste/...)، وتسجيل
 * الأحداث النظامية (started/submitted/auto_submitted) اللي ExamAttemptService
 * بينادّيها هي نفسها — الخدمة دي عمدًا من غير أي dependency على
 * ExamAttemptService (اتجاه واحد بس: ExamAttemptService -> ExamSecurityService)
 * عشان نتجنب circular dependency؛ قرار "هل نعمل auto-submit بسبب تخطي
 * الحد الأقصى للمخالفات؟" بياخده الـ controller (ExamSecurityApiController)
 * بعد ما يقرا threshold_exceeded من recordClientEvent()، مش الخدمة دي.
 *
 * IMPORTANT (Phase 15 — الصراحة مع حدود الحماية): التحكمات هنا كلها
 * "browser-level" بس — تسجيل محاولة نسخ/لصق/قص عبر الـ clipboard events،
 * مفيش أي ضمان حقيقي ضد Google/ChatGPT/screen recording/جهاز تاني/أي حاجة
 * على مستوى نظام التشغيل. الـ metadata المرنة (json) هي اللي هتسمح
 * لـ **UIP Secure Exam Browser** مستقبلي إنه يبعت تفاصيل أدق من غير ما
 * نحتاج migration جديدة.
 */
class ExamSecurityService
{
    /** الأحداث اللي متصفح الطالب (Secure Exam frontend) يقدر يبعتها بنفسه عبر recordClientEvent(). أي حاجة تانية (بما فيها exam_started/exam_submitted/auto_submitted/answer_saved) system-only — راجع logSystemEvent(). */
    public const CLIENT_EVENTS = [
        'fullscreen_entered', 'fullscreen_exited',
        'tab_switch', 'window_blur', 'window_focus',
        'copy_attempt', 'paste_attempt', 'cut_attempt',
        'question_changed', 'time_expired',
    ];

    /** الأحداث اللي فعليًا بتزوّد exam_attempts.violations_count وبتتحسب في حد الـ max_violations — الباقي (fullscreen_entered/window_focus/question_changed/time_expired) إعلامي بحت. */
    public const VIOLATION_EVENTS = [
        'fullscreen_exited', 'tab_switch', 'window_blur',
        'copy_attempt', 'paste_attempt', 'cut_attempt',
    ];

    public function __construct(
        private ExamSecurityEventRepository $events
    ) {
    }

    // -----------------------------------------------------------------
    // Client-reported events (Phases 13-15)
    // -----------------------------------------------------------------

    /**
     * بتسجل حدث جاي من متصفح الطالب وقت محاولة شغالة، وبتزوّد
     * violations_count لو الحدث ده من VIOLATION_EVENTS. بترجع كل المعلومات
     * اللي الـ controller محتاجها عشان يقرر لو المحاولة توصل الحد الأقصى.
     *
     * @throws \InvalidArgumentException لو event_type مش من CLIENT_EVENTS، أو المحاولة خلصت أصلاً.
     * @return array{event: ExamSecurityEvent, violations_count: int, max_violations: ?int, threshold_exceeded: bool}
     */
    public function recordClientEvent(ExamAttempt $attempt, ?array $metadata, string $eventType): array
    {
        if (!in_array($eventType, self::CLIENT_EVENTS, true)) {
            throw new \InvalidArgumentException('Invalid security event type.');
        }
        if (!$attempt->isActive()) {
            throw new \InvalidArgumentException('This attempt is no longer in progress.');
        }

        $isViolation = in_array($eventType, self::VIOLATION_EVENTS, true);

        $event = $this->events->create([
            'exam_attempt_id' => $attempt->id,
            'exam_id'         => $attempt->exam_id,
            'student_id'      => $attempt->student_id,
            'event_type'      => $eventType,
            'is_violation'    => $isViolation,
            'metadata'        => $metadata,
            'occurred_at'     => now(),
        ]);

        if ($isViolation) {
            $attempt->violations_count = $attempt->violations_count + 1;
            $attempt->save();
        }

        $exam = $attempt->exam ?? $attempt->exam()->first();
        $maxViolations = $exam?->max_violations;

        return [
            'event'              => $event,
            'violations_count'   => $attempt->violations_count,
            'max_violations'     => $maxViolations !== null ? (int) $maxViolations : null,
            'threshold_exceeded' => $maxViolations !== null && $attempt->violations_count >= (int) $maxViolations,
        ];
    }

    // -----------------------------------------------------------------
    // System events (Phase 16 — started/submitted/auto_submitted)
    // -----------------------------------------------------------------

    /** بتتنادى من ExamAttemptService بس — أحداث نظامية عمرها ما بتتقبل من العميل مباشرة، وعمرها ما بتتحسب "مخالفة". */
    public function logSystemEvent(ExamAttempt $attempt, string $eventType, ?array $metadata = null): ExamSecurityEvent
    {
        return $this->events->create([
            'exam_attempt_id' => $attempt->id,
            'exam_id'         => $attempt->exam_id,
            'student_id'      => $attempt->student_id,
            'event_type'      => $eventType,
            'is_violation'    => false,
            'metadata'        => $metadata,
            'occurred_at'     => now(),
        ]);
    }

    // -----------------------------------------------------------------
    // Instructor timeline
    // -----------------------------------------------------------------

    /** @return array<int,array<string,mixed>> كل أحداث محاولة واحدة بترتيب زمني — للمدرس، وقت مراجعة المحاولة. */
    public function timelineForAttempt(ExamAttempt $attempt): array
    {
        return array_map(fn (ExamSecurityEvent $e) => [
            'id'           => $e->id,
            'event_type'   => $e->event_type,
            'is_violation' => $e->is_violation,
            'metadata'     => $e->metadata,
            'occurred_at'  => $e->occurred_at,
        ], $this->events->forAttempt($attempt->id));
    }
}
