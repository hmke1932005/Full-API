<?php

namespace App\Services;

use App\Models\Project;
use App\Repositories\ProjectGradeRepository;
use Illuminate\Support\Facades\Log;

/**
 * منقولة من app/Services/ProjectGradingService.php القديمة — بند 14
 * (Graduation). بتقف وراء فورم "Grade project" بتاعة بورتال المشرف: صف
 * project_grades (migration 108) + rubric حر project_grade_criteria
 * المشرف بيعرّفه بنفسه لكل مشروع (مفيش rubric ثابت على مستوى المنصة).
 *
 * المشرف يقدر يحفظ 'draft' أي عدد مرات وهو لسه شغال عليه؛ 'final' بتقفل
 * graded_at وتبلّغ الطالب — نفس شكل ProjectApprovalService::approve()/
 * reject() بالظبط (سجل + audit log + notify).
 *
 * عمدًا مش بتلمس students.gpa — تجميع المشاريع المُقيّمة في GPA حقيقي
 * شغلانة تانية أكبر (فجوة الـ GPA في تدقيق الـ spec) مش هنا.
 */
class ProjectGradingService
{
    /** نفس السلم القديم بالظبط — مش قابل للتخصيص لكل جامعة لسه. */
    private const LETTER_SCALE = [
        ['min' => 90, 'letter' => 'A'],
        ['min' => 80, 'letter' => 'B'],
        ['min' => 70, 'letter' => 'C'],
        ['min' => 60, 'letter' => 'D'],
        ['min' => 0,  'letter' => 'F'],
    ];

    /** المشروع يتقيّم بس لو عدّى فعليًا بمراجعة — عمره ما يبقى مسودة لسه ما اتقدمتش. */
    private const GRADABLE_STATUSES = ['submitted', 'published', 'rejected'];

    public function __construct(
        private ProjectGradeRepository $grades,
        private AuditLogService $auditLog,
        private NotificationService $notifications
    ) {
    }

    /**
     * التقييم + الـ rubric لمشروع واحد، بالشكل اللي فورم التقييم محتاجه.
     * لو المشروع ما اتقيّمش قبل كده، بترجع تقييم null + سطر معيار فاضي
     * واحد (فورم فاضي).
     */
    public function forProject(int $projectId): array
    {
        $grade = $this->grades->findByProject($projectId);
        if (!$grade) {
            return [
                'grade'    => null,
                'criteria' => [['criterion' => '', 'max_score' => '', 'score' => '', 'comments' => '']],
            ];
        }

        $criteria = $this->grades->criteriaFor($grade->id)->map(fn ($c) => [
            'criterion' => $c->criterion,
            'max_score' => $c->max_score,
            'score'     => $c->score,
            'comments'  => $c->comments,
        ])->all();

        return ['grade' => $grade, 'criteria' => $criteria];
    }

    /**
     * @param array<int,array{criterion:string,max_score:mixed,score:mixed,comments:?string}> $criteria
     * @return array{success:bool, message:string}
     */
    public function saveGrade(
        int $projectId,
        $graderUserId,
        array $criteria,
        ?string $overallComments,
        bool $finalize,
        string $locale = 'ar'
    ): array {
        $project = Project::find($projectId);
        if (!$project || !in_array($project->status, self::GRADABLE_STATUSES, true)) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'لا يمكن تقييم هذا المشروع في حالته الحالية.'
                : 'This project cannot be graded in its current status.'];
        }

        $existing = $this->grades->findByProject($projectId);
        // إعادة فتح تقييم نهائي للتعديل مسموحة (الأخطاء بتحصل) — أي حفظ
        // عادي (مش finalize تاني) بيرجّع status لـ 'draft' زي ما هو تحت
        // في $gradeData، فالمشرف لازم يعتمد بشكل صريح تاني بدل ما تعديل
        // عرضي يسيب status='final' قديمة جنب أرقام جديدة.

        $clean = [];
        $totalScore = 0.0;
        $totalMax = 0.0;
        $hasAnyScore = false;

        foreach ($criteria as $row) {
            $criterion = trim((string) ($row['criterion'] ?? ''));
            $maxScore = $row['max_score'] ?? null;
            $score = $row['score'] ?? null;

            if ($criterion === '' && ($maxScore === null || $maxScore === '')) {
                continue; // سطر فاضي في آخر الفورم — يتجاهل بهدوء
            }
            if ($criterion === '' || $maxScore === null || $maxScore === '' || !is_numeric($maxScore) || (float) $maxScore <= 0) {
                return ['success' => false, 'message' => $locale === 'ar'
                    ? 'كل معيار تقييم يحتاج اسم ودرجة عظمى أكبر من صفر.'
                    : 'Every rubric criterion needs a name and a max score greater than zero.'];
            }
            $maxScore = (float) $maxScore;

            $scoreValue = null;
            if ($score !== null && $score !== '') {
                if (!is_numeric($score) || (float) $score < 0 || (float) $score > $maxScore) {
                    return ['success' => false, 'message' => $locale === 'ar'
                        ? "الدرجة المدخلة لمعيار \"{$criterion}\" غير صحيحة (يجب أن تكون بين 0 والدرجة العظمى)."
                        : "The score for \"{$criterion}\" is invalid (must be between 0 and its max score)."];
                }
                $scoreValue = (float) $score;
                $hasAnyScore = true;
                $totalScore += $scoreValue;
            }
            $totalMax += $maxScore;

            $clean[] = [
                'criterion' => $criterion,
                'max_score' => $maxScore,
                'score'     => $scoreValue,
                'comments'  => ($row['comments'] ?? '') !== '' ? $row['comments'] : null,
            ];
        }

        if (empty($clean)) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'أضف معيار تقييم واحد على الأقل.'
                : 'Add at least one rubric criterion.'];
        }

        if ($finalize && !$hasAnyScore) {
            return ['success' => false, 'message' => $locale === 'ar'
                ? 'لا يمكن اعتماد التقييم بدون إدخال درجات.'
                : "A grade can't be finalized without any scores entered."];
        }
        if ($finalize) {
            foreach ($clean as $row) {
                if ($row['score'] === null) {
                    return ['success' => false, 'message' => $locale === 'ar'
                        ? "لازم تدخل درجة لكل معيار قبل اعتماد التقييم النهائي (\"{$row['criterion']}\" بدون درجة)."
                        : "Every criterion needs a score before finalizing (\"{$row['criterion']}\" has none)."];
                }
            }
        }

        $percentage = $totalMax > 0 && $hasAnyScore ? ($totalScore / $totalMax) * 100 : null;
        $letter = $percentage !== null ? $this->letterFor($percentage) : null;

        $gradeData = [
            'project_id'       => $projectId,
            'graded_by'        => $graderUserId,
            'total_score'      => $hasAnyScore ? round($totalScore, 2) : null,
            'max_score'        => round($totalMax, 2),
            'letter_grade'     => $letter,
            'status'           => $finalize ? 'final' : 'draft',
            'overall_comments' => $overallComments !== '' ? $overallComments : null,
            'graded_at'        => $finalize ? now() : ($existing->graded_at ?? null),
        ];

        if ($existing) {
            $before = $existing->toArray();
            $existing->fill($gradeData);
            $existing->save();
            $gradeId = (int) $existing->id;
        } else {
            $before = null;
            $grade = $this->grades->create($gradeData);
            $gradeId = (int) $grade->id;
        }

        $this->grades->replaceCriteria($gradeId, $clean);

        $this->auditLog->record($graderUserId, 'supervisor.project_grade_save', 'Project', $projectId, $before, array_merge($gradeData, [
            'criteria_count' => count($clean),
        ]));
        Log::info('Project grade saved', ['project_id' => $projectId, 'grader_id' => $graderUserId, 'finalized' => $finalize]);

        if ($finalize) {
            $title = $project->title_en ?: $project->title_ar;
            $scoreLabel = $hasAnyScore ? (round($totalScore, 1) . '/' . round($totalMax, 1) . ($letter ? " ({$letter})" : '')) : '';
            $this->notifications->notify(
                $project->owner_id,
                'project_graded',
                $locale === 'ar' ? "تم تقييم مشروعك \"{$title}\"" : "Your project \"{$title}\" was graded",
                $locale === 'ar'
                    ? ($scoreLabel !== '' ? "الدرجة: {$scoreLabel}." : 'راجع تفاصيل التقييم من صفحة المشروع.')
                    : ($scoreLabel !== '' ? "Score: {$scoreLabel}." : 'See the project page for the full breakdown.'),
                '/student/projects/' . $project->uuid
            );
        }

        return ['success' => true, 'message' => $finalize
            ? ($locale === 'ar' ? 'تم اعتماد التقييم وإخطار الطالب.' : 'Grade finalized and the student was notified.')
            : ($locale === 'ar' ? 'تم حفظ التقييم كمسودة.' : 'Grade saved as a draft.')];
    }

    private function letterFor(float $percentage): string
    {
        foreach (self::LETTER_SCALE as $tier) {
            if ($percentage >= $tier['min']) {
                return $tier['letter'];
            }
        }
        return 'F';
    }
}
