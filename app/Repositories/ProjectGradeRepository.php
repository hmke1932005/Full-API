<?php

namespace App\Repositories;

use App\Models\ProjectGrade;
use App\Models\ProjectGradeCriterion;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/ProjectGradeRepository.php القديمة —
 * بند 14 (Graduation). project_grade_criteria بتتستبدل بالكامل في كل
 * save() بدل ما تتقارن سطر سطر، لأن فورم التقييم دايمًا بيبعت الـ rubric
 * كله من جديد — نفس تعليق replaceCriteria() القديم بالظبط.
 */
class ProjectGradeRepository
{
    public function findByProject($projectId): ?ProjectGrade
    {
        return ProjectGrade::where('project_id', $projectId)->first();
    }

    public function find($id): ?ProjectGrade
    {
        return ProjectGrade::find($id);
    }

    /** @return \Illuminate\Support\Collection<int,ProjectGradeCriterion> */
    public function criteriaFor($gradeId)
    {
        return ProjectGradeCriterion::where('project_grade_id', $gradeId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function create(array $data): ProjectGrade
    {
        return ProjectGrade::create($data);
    }

    public function replaceCriteria($gradeId, array $rows): void
    {
        ProjectGradeCriterion::where('project_grade_id', $gradeId)->delete();
        $order = 0;
        foreach ($rows as $row) {
            ProjectGradeCriterion::create([
                'project_grade_id' => $gradeId,
                'criterion'        => $row['criterion'],
                'max_score'        => $row['max_score'],
                'score'            => $row['score'],
                'comments'         => $row['comments'] ?? null,
                'sort_order'       => $order++,
            ]);
        }
    }

    /**
     * الدرجات النهائية (status='final') لمجموعة owner_id (users.id) —
     * GraduationService::checkEligibility()/transcriptFor() بتستخدمها
     * عشان تلاقي أحدث تقييم نهائي معتمد لمشروع تخرج الطالب.
     */
    public function finalGradesForOwners(array $ownerUserIds): array
    {
        if (empty($ownerUserIds)) {
            return [];
        }

        return DB::table('project_grades as pg')
            ->join('projects as p', 'p.id', '=', 'pg.project_id')
            ->select('pg.*', 'p.owner_id', 'p.title_ar', 'p.title_en')
            ->where('pg.status', 'final')
            ->whereIn('p.owner_id', $ownerUserIds)
            ->orderByDesc('pg.graded_at')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }
}
