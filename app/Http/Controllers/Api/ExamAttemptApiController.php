<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ExamAttemptRepository;
use App\Repositories\StudentRepository;
use App\Services\ExamAttemptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * سطح /api/v1/exam-system/{my-exams/{id}/attempts, attempts/*} — Round 3
 * (Attempts + Timer + Auto-save). resolveStudent() نفس نسخة
 * StudentExamApiController::resolveStudent() بالظبط (مكررة عمدًا زي باقي
 * الكونترولرز في المشروع ده — مفيش trait مشترك للـ RBAC helper الصغير ده،
 * راجع نفس القرار في ExamSystemApiController/StudentExamApiController).
 *
 * كل الميثودز هنا بتمر أول حاجة بـ findOwned() (الملكية = student_id بتاع
 * المحاولة) قبل أي حاجة — طالب تاني عمره ما يقدر يشوف أو يلمس محاولة مش
 * بتاعته حتى لو عرف الـ id (زي "Changing attempt ownership" المذكورة
 * صراحة في Phase الأمان بالسبك).
 */
class ExamAttemptApiController extends Controller
{
    public function __construct(
        private ExamAttemptService $examAttempts,
        private ExamAttemptRepository $attemptsRepo,
        private StudentRepository $students
    ) {
    }

    private function resolveStudent(Request $request): array
    {
        if ($request->attributes->get('uip_role') !== 'student') {
            return [null, $this->apiError('Only student accounts can access the exam system.', null, 403)];
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $student = $this->students->findByUserId($userId);
        if (!$student) {
            return [null, $this->apiError('Student profile not found for this account.', null, 404)];
        }

        return [$student, null];
    }

    /** GET /api/v1/exam-system/my-exams/{id}/attempts — تاريخ محاولات الطالب على الامتحان ده. */
    public function indexForExam(Request $request, $examId)
    {
        [$student, $err] = $this->resolveStudent($request);
        if ($err) {
            return $err;
        }

        $attempts = array_map(fn ($a) => [
            'id'               => $a->id,
            'attempt_number'   => $a->attempt_number,
            'status'           => $a->status,
            'started_at'       => $a->started_at,
            'submitted_at'     => $a->submitted_at,
            'score'            => $a->score !== null ? (float) $a->score : null,
            'percentage'       => $a->percentage !== null ? (float) $a->percentage : null,
        ], $this->examAttempts->listAttempts($student->id, $examId));

        return $this->apiSuccess($attempts, 'Attempts retrieved successfully.');
    }

    /** POST /api/v1/exam-system/my-exams/{id}/attempts — يبدأ محاولة جديدة، أو يكمل واحدة شغالة لو موجودة. */
    public function start(Request $request, $examId)
    {
        [$student, $err] = $this->resolveStudent($request);
        if ($err) {
            return $err;
        }

        try {
            $result = $this->examAttempts->startAttempt($examId, $student->id, $student->university_id);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $detail = $this->examAttempts->attemptDetail($result['attempt']);
        $message = $result['resumed'] ? 'Resuming your existing attempt.' : 'Exam attempt started.';

        return $this->apiSuccess($detail, $message, $result['resumed'] ? 200 : 201);
    }

    /** GET /api/v1/exam-system/attempts/{id} */
    public function show(Request $request, $id)
    {
        [$student, $err] = $this->resolveStudent($request);
        if ($err) {
            return $err;
        }

        $attempt = $this->findOwnedAttempt($id, $student->id);
        if (!$attempt) {
            return $this->apiError('Attempt not found.', null, 404);
        }

        return $this->apiSuccess($this->examAttempts->attemptDetail($attempt), 'Attempt retrieved successfully.');
    }

    /** PUT /api/v1/exam-system/attempts/{id}/answers/{examQuestionId} — Auto-save: create/update. */
    public function saveAnswer(Request $request, $id, $examQuestionId)
    {
        [$student, $err] = $this->resolveStudent($request);
        if ($err) {
            return $err;
        }

        $attempt = $this->findOwnedAttempt($id, $student->id);
        if (!$attempt) {
            return $this->apiError('Attempt not found.', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'selected_option_ids'   => 'nullable|array',
            'selected_option_ids.*' => 'integer',
            'answer_text'           => 'nullable|string|max:20000',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }

        try {
            $result = $this->examAttempts->saveAnswer($attempt, $examQuestionId, $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess([
            'deleted'                => $result['deleted'],
            'status'                 => $result['attempt']->status,
            'time_remaining_seconds' => $result['attempt']->remainingSeconds(),
        ], $result['deleted'] ? 'Answer cleared.' : 'Answer saved.');
    }

    /** DELETE /api/v1/exam-system/attempts/{id}/answers/{examQuestionId} — Auto-save: explicit delete. */
    public function deleteAnswer(Request $request, $id, $examQuestionId)
    {
        [$student, $err] = $this->resolveStudent($request);
        if ($err) {
            return $err;
        }

        $attempt = $this->findOwnedAttempt($id, $student->id);
        if (!$attempt) {
            return $this->apiError('Attempt not found.', null, 404);
        }

        try {
            $attempt = $this->examAttempts->deleteAnswer($attempt, $examQuestionId);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        return $this->apiSuccess([
            'status'                 => $attempt->status,
            'time_remaining_seconds' => $attempt->remainingSeconds(),
        ], 'Answer cleared.');
    }

    /** POST /api/v1/exam-system/attempts/{id}/submit */
    public function submit(Request $request, $id)
    {
        [$student, $err] = $this->resolveStudent($request);
        if ($err) {
            return $err;
        }

        $attempt = $this->findOwnedAttempt($id, $student->id);
        if (!$attempt) {
            return $this->apiError('Attempt not found.', null, 404);
        }

        $attempt = $this->examAttempts->submitAttempt($attempt);

        return $this->apiSuccess($this->examAttempts->attemptDetail($attempt), 'Exam submitted successfully.');
    }

    /** GET /api/v1/exam-system/attempts/{id}/result — Round 4 (Phase 24). نتيجة الطالب، محكومة بـ result_visibility. */
    public function result(Request $request, $id)
    {
        [$student, $err] = $this->resolveStudent($request);
        if ($err) {
            return $err;
        }

        $attempt = $this->findOwnedAttempt($id, $student->id);
        if (!$attempt) {
            return $this->apiError('Attempt not found.', null, 404);
        }

        return $this->apiSuccess($this->examAttempts->studentResult($attempt), 'Result retrieved successfully.');
    }

    private function findOwnedAttempt($id, $studentId)
    {
        return $this->attemptsRepo->findOwned($id, $studentId);
    }
}
