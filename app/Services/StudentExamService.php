<?php

namespace App\Services;

use App\Models\Exam;
use App\Repositories\ExamRepository;
use App\Repositories\ExamTargetRepository;

/**
 * Exam & Assessment System — Round 2. سطح قراءة-فقط للطالب: "امتحاناتي"
 * (Phase 8/9 في السبك، جزء الطالب). عمدًا بيرجع بيانات الامتحان الوصفية
 * بس (عنوان/مدة/درجة/تواريخ) — من غير أي سؤال أو اختيار، حتى لو الطالب
 * مؤهل يشوف الامتحان؛ محتوى الأسئلة نفسه هيتكشف بس وقت بدء المحاولة
 * (exam_attempts، Round 3) عشان محدش يقدر "يقرا" الامتحان من غير ما
 * يبدأ محاولة رسمية — نفس روح Phase 9 "Never rely only on frontend
 * validation"، بس هنا بتترجم لـ "الباك إند نفسه ميكشفش المحتوى قبل
 * أوانه" مش بس فحص صلاحية.
 */
class StudentExamService
{
    public function __construct(
        private ExamRepository $exams,
        private ExamTargetRepository $targets
    ) {
    }

    /** @return array<int,array<string,mixed>> كل الامتحانات المنشورة/المجدولة اللي الطالب ده مستهدف بيها. */
    public function listMyExams($studentId, $universityId): array
    {
        $examIds = $this->targets->examIdsForStudent($studentId, $universityId);
        if (!$examIds) {
            return [];
        }

        $exams = array_filter(
            array_map(fn ($id) => $this->exams->find($id), $examIds),
            fn (?Exam $e) => $e !== null && in_array($e->status, ['published', 'scheduled'], true)
        );

        usort($exams, fn (Exam $a, Exam $b) => ($a->start_at?->timestamp ?? PHP_INT_MAX) <=> ($b->start_at?->timestamp ?? PHP_INT_MAX));

        return array_map(fn (Exam $e) => $this->summaryFor($e), $exams);
    }

    /** بيانات وصفية لامتحان واحد — null لو مش موجود أو الطالب مش مؤهل أو لسه مش منشور. */
    public function examSummaryForStudent($examId, $studentId, $universityId): ?array
    {
        $exam = $this->exams->find($examId);
        if (!$exam || (int) $exam->university_id !== (int) $universityId) {
            return null;
        }
        if (!in_array($exam->status, ['published', 'scheduled'], true)) {
            return null;
        }
        if (!$this->targets->studentIsEligibleForExam($examId, $studentId)) {
            return null;
        }

        return $this->summaryFor($exam);
    }

    private function summaryFor(Exam $exam): array
    {
        return [
            'id'                 => $exam->id,
            'title'              => $exam->title,
            'description'        => $exam->description,
            'subject'            => $exam->subject,
            'academic_year'      => $exam->academic_year,
            'semester'           => $exam->semester,
            'duration_minutes'   => $exam->duration_minutes,
            'start_at'           => $exam->start_at,
            'end_at'             => $exam->end_at,
            'max_attempts'       => $exam->max_attempts,
            'passing_score'      => $exam->passing_score !== null ? (float) $exam->passing_score : null,
            'total_marks'        => (float) $exam->total_marks,
            // Round 7 — عدد الأسئلة "المعلن" (نفسه لكل طالب دايمًا: يدوي +
            // مجموع questions_to_select لكل الـ pools)، مش عدد صفوف
            // exam_questions الفعلي (اللي بيكبر مع كل طالب يبدأ محاولة
            // ويسحب أسئلة جديدة من أي pool — راجع docblock ExamSystemService::examDetail()).
            'question_count'     => count($this->exams->manualQuestionsFor($exam->id))
                + array_sum(array_map(fn ($c) => (int) $c->questions_to_select, $this->exams->poolConfigsFor($exam->id))),
            'instructions'       => $exam->instructions,
            'result_visibility'  => $exam->result_visibility,
            'secure_mode_enabled' => (bool) $exam->secure_mode_enabled,
            'max_violations'     => $exam->max_violations !== null ? (int) $exam->max_violations : null,
            'status'             => $exam->status,
        ];
    }
}
