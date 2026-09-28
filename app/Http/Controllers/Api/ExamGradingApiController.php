<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\AcademicStaffRepository;
use App\Repositories\ExamAttemptRepository;
use App\Repositories\ExamGradeRepository;
use App\Services\ExamGradingService;
use App\Services\ExamSystemService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * سطح /api/v1/exam-system/exams/{id}/attempts/* — Round 4 (Grading Core،
 * Phases 17/22). كونترولر جديد منفصل عن ExamSystemApiController عمدًا (مش
 * إضافة ميثودز جواه) — هنا كل حاجة بتاخد examId في المسار وبتتفحص ملكية
 * الامتحان أولاً، بعدين ملكية المحاولة (إنها فعلاً تابعة للامتحان ده) —
 * نفس فكرة "Changing attempt ownership" اللي ExamAttemptApiController
 * بيمنعها للطالب، هنا بمنطقها المعكوس: مدرس تاني عمره ما يقدر يصحح أو
 * حتى يشوف محاولة على امتحان مش بتاعه، حتى لو عرف attemptId صح.
 *
 * requireAcademicStaff()/resolveStaff() نفس نسخة ExamSystemApiController
 * بالظبط (مكررة عمدًا — راجع نفس القرار هناك).
 */
class ExamGradingApiController extends Controller
{
    public function __construct(
        private ExamGradingService $grading,
        private ExamSystemService $examSystem,
        private ExamAttemptRepository $attemptsRepo,
        private ExamGradeRepository $gradesRepo,
        private AcademicStaffRepository $staffRepo
    ) {
    }

    private function requireAcademicStaff(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'academic_staff') {
            return $this->apiError('Only academic staff accounts can access the exam system.', null, 403);
        }
        return null;
    }

    private function resolveStaff(Request $request): array
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $staff = $this->staffRepo->findByUserId($userId);
        if (!$staff) {
            return [null, $this->apiError('No academic staff profile found for this account.', null, 404)];
        }
        return [$staff, null];
    }

    /** بترجع [exam, null] لو الامتحان تابع للمدرس ده، أو [null, JsonResponse] غير كده. */
    private function resolveOwnedExam(Request $request, $examId, $staff): array
    {
        $exam = $this->examSystem->findOwnedExam($examId, $staff->id);
        if (!$exam) {
            return [null, $this->apiError('Exam not found.', null, 404)];
        }
        return [$exam, null];
    }

    /** بترجع [attempt, null] لو المحاولة موجودة وفعلاً تابعة للامتحان ده، أو [null, JsonResponse] غير كده. */
    private function resolveAttemptForExam($attemptId, $exam): array
    {
        $attempt = $this->attemptsRepo->find($attemptId);
        if (!$attempt || (int) $attempt->exam_id !== (int) $exam->id) {
            return [null, $this->apiError('Attempt not found.', null, 404)];
        }
        return [$attempt, null];
    }

    // -----------------------------------------------------------------
    // Attempts list / detail — /exams/{id}/attempts[/{attemptId}]
    // -----------------------------------------------------------------

    /** GET /api/v1/exam-system/exams/{id}/attempts — ملخص كل محاولات الطلاب على الامتحان ده + تقدم التصحيح. */
    public function indexAttempts(Request $request, $examId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        [$exam, $err] = $this->resolveOwnedExam($request, $examId, $staff);
        if ($err) {
            return $err;
        }

        return $this->apiSuccess($this->grading->listAttemptsForExam($exam), 'Attempts retrieved successfully.');
    }

    /** GET /api/v1/exam-system/exams/{id}/attempts/{attemptId} — التفاصيل الكاملة (أسئلة + إجابات + درجات) لمحاولة واحدة. */
    public function showAttempt(Request $request, $examId, $attemptId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        [$exam, $err] = $this->resolveOwnedExam($request, $examId, $staff);
        if ($err) {
            return $err;
        }
        [$attempt, $err] = $this->resolveAttemptForExam($attemptId, $exam);
        if ($err) {
            return $err;
        }

        return $this->apiSuccess($this->grading->attemptDetailForInstructor($attempt), 'Attempt retrieved successfully.');
    }

    // -----------------------------------------------------------------
    // Manual grading — /exams/{id}/attempts/{attemptId}/grades/{examQuestionId}
    // -----------------------------------------------------------------

    /** PUT — تصحيح يدوي لسؤال واحد (أو override على تصحيح أوتوماتيك). كل نداء بيسجل سطر في exam_grade_history. */
    public function gradeAnswer(Request $request, $examId, $attemptId, $examQuestionId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        [$exam, $err] = $this->resolveOwnedExam($request, $examId, $staff);
        if ($err) {
            return $err;
        }
        [$attempt, $err] = $this->resolveAttemptForExam($attemptId, $exam);
        if ($err) {
            return $err;
        }

        $validator = Validator::make($request->all(), [
            'marks_awarded' => 'required|numeric|min:0',
            'feedback'      => 'nullable|string|max:5000',
            'reason'        => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        try {
            $grade = $this->grading->gradeManually(
                $attempt,
                $examQuestionId,
                (float) $request->input('marks_awarded'),
                $request->input('feedback'),
                $staff,
                $request->input('reason')
            );
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess([
            'id'             => $grade->id,
            'marks_awarded'  => (float) $grade->marks_awarded,
            'max_marks'      => (float) $grade->max_marks,
            'feedback'       => $grade->feedback,
            'source'         => $grade->source,
            'graded_at'      => $grade->graded_at,
            'attempt_status' => $attempt->refresh()->status,
        ], 'Grade saved.');
    }

    /** GET — سجل تعديلات درجة سؤال واحد جوه محاولة (Phase 22). */
    public function gradeHistory(Request $request, $examId, $attemptId, $examQuestionId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        [$exam, $err] = $this->resolveOwnedExam($request, $examId, $staff);
        if ($err) {
            return $err;
        }
        [$attempt, $err] = $this->resolveAttemptForExam($attemptId, $exam);
        if ($err) {
            return $err;
        }

        $grade = $this->gradesRepo->findForQuestion($attempt->id, $examQuestionId);
        if (!$grade) {
            return $this->apiSuccess([], 'No grade recorded yet for this question.');
        }

        $history = array_map(fn ($h) => [
            'id'              => $h->id,
            'previous_score'  => $h->previous_score !== null ? (float) $h->previous_score : null,
            'new_score'       => (float) $h->new_score,
            'source'          => $h->source,
            'reason'          => $h->reason,
            'changed_at'      => $h->changed_at,
        ], $this->grading->historyFor($grade));

        return $this->apiSuccess($history, 'Grade history retrieved successfully.');
    }

    /** POST — بيعيد تشغيل التصحيح الأوتوماتيك للأسئلة الموضوعية بس (بعد تعديل بنك الأسئلة مثلاً)، من غير ما يلمس أي درجة عدّلها المدرس يدويًا. */
    public function autoGrade(Request $request, $examId, $attemptId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        [$exam, $err] = $this->resolveOwnedExam($request, $examId, $staff);
        if ($err) {
            return $err;
        }
        [$attempt, $err] = $this->resolveAttemptForExam($attemptId, $exam);
        if ($err) {
            return $err;
        }

        if (in_array($attempt->status, ['in_progress', 'cancelled'], true)) {
            return $this->apiError('This attempt has not been submitted yet.', null, 422);
        }

        $count = $this->grading->autoGradeAttempt($attempt);

        return $this->apiSuccess([
            'regraded_count' => $count,
            'attempt_status' => $attempt->refresh()->status,
        ], 'Automatic grading re-run.');
    }

    // -----------------------------------------------------------------
    // AI grading (Round 6, Phase 18/23) — /exams/{id}/attempts/{attemptId}/grades/{examQuestionId}/accept-ai|ai-regrade
    // -----------------------------------------------------------------

    /** POST — المدرس بيقبل درجة الـ AI زي ما هي، وبتتحول لـ source=instructor (نفس فلسفة gradeManually بس من غير تعديل رقم). */
    public function acceptAiGrade(Request $request, $examId, $attemptId, $examQuestionId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        [$exam, $err] = $this->resolveOwnedExam($request, $examId, $staff);
        if ($err) {
            return $err;
        }
        [$attempt, $err] = $this->resolveAttemptForExam($attemptId, $exam);
        if ($err) {
            return $err;
        }

        $grade = $this->gradesRepo->findForQuestion($attempt->id, $examQuestionId);
        if (!$grade) {
            return $this->apiError('No grade recorded yet for this question.', null, 404);
        }

        try {
            $grade = $this->grading->acceptAiGrade($grade, $staff);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess([
            'id'             => $grade->id,
            'marks_awarded'  => (float) $grade->marks_awarded,
            'max_marks'      => (float) $grade->max_marks,
            'source'         => $grade->source,
            'graded_at'      => $grade->graded_at,
            'attempt_status' => $attempt->refresh()->status,
        ], 'AI grade accepted.');
    }

    /** POST — إعادة تشغيل التصحيح بالـ AI لسؤال essay/short_answer واحد (بعد تعديل model answer/rubric مثلاً). بيرجع الدرجة القديمة لغاية ما الـ job يخلص. */
    public function aiRegrade(Request $request, $examId, $attemptId, $examQuestionId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        [$exam, $err] = $this->resolveOwnedExam($request, $examId, $staff);
        if ($err) {
            return $err;
        }
        [$attempt, $err] = $this->resolveAttemptForExam($attemptId, $exam);
        if ($err) {
            return $err;
        }

        try {
            $grade = $this->grading->regradeQuestionWithAi($attempt, $examQuestionId, $staff);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess([
            'id'             => $grade->id,
            'marks_awarded'  => $grade->marks_awarded !== null ? (float) $grade->marks_awarded : null,
            'max_marks'      => (float) $grade->max_marks,
            'source'         => $grade->source,
        ], 'AI regrade queued.');
    }

    // -----------------------------------------------------------------
    // Result publishing (result_visibility = 'manual') — /exams/{id}/publish-results
    // -----------------------------------------------------------------

    public function publishResults(Request $request, $examId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        [$exam, $err] = $this->resolveOwnedExam($request, $examId, $staff);
        if ($err) {
            return $err;
        }

        $exam = $this->grading->publishResults($exam);

        return $this->apiSuccess(['results_published_at' => $exam->results_published_at], 'Results published to students.');
    }

    public function unpublishResults(Request $request, $examId)
    {
        if ($err = $this->requireAcademicStaff($request)) {
            return $err;
        }
        [$staff, $err] = $this->resolveStaff($request);
        if ($err) {
            return $err;
        }
        [$exam, $err] = $this->resolveOwnedExam($request, $examId, $staff);
        if ($err) {
            return $err;
        }

        $exam = $this->grading->unpublishResults($exam);

        return $this->apiSuccess(['results_published_at' => $exam->results_published_at], 'Results unpublished.');
    }
}
