<?php

namespace App\Services;

use App\Models\ProjectApproval;
use App\Repositories\AIAnalysisRepository;
use App\Repositories\FacultyRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\UniversityRepository;
use Illuminate\Support\Facades\Log;

/**
 * منقولة جزئيًا من app/Services/ProjectApprovalService.php القديمة — مسار
 * University (queueForUniversity/approve/reject/requestChanges/
 * requiresAiAcknowledgment/universityIdForUser) + مسار Faculty
 * (facultyIdForUser/queueForFaculty/*ForFaculty()، بند 10)، اللي
 * UniversityApprovalsApiController وFacultyApprovalsApiController
 * محتاجينهم. مسار Admin (platformOversight... و override()، بند 11 مرحلة 2)
 * مضاف تحت — إشراف قراءة-فقط على مستوى المنصة + override استثنائي واحد،
 * القرار العادي approve/reject/request-changes فاضل بس عند الجامعة/الكلية
 * زي ما هو، مش بيتكرر هنا. latestDecisionForOwner() كمان مضافة، بانر
 * التغذية الراجعة للطالب (سبب الرفض/التعديلات المطلوبة) في صفحة تفاصيل
 * مشروعه.
 *
 * بوابة المراجعة البشرية الإلزامية (AI acknowledgment، migration 121):
 * القرار نفسه دايمًا بشري (reviewer_id) — درجة الجاهزية/التصنيف التلقائي
 * في الطابور (toQueueRow()) عمرها ما قررت حاجة لوحدها. اللي كان ناقص هو
 * إثبات إن المراجع فعلًا شافها قبل ما يقرر: approve()/reject()/
 * requestChanges() بترفض تسجل قرار (بترجع false، زي أي فشل تاني) على
 * مشروع عنده درجة جاهزية و/أو تصنيف إلا لو $aiAcknowledged = true، وبتحفظ
 * نفس المؤشرات اللي كانت ظاهرة وقتها في project_approvals.ai_output_snapshot.
 */
class ProjectApprovalService
{
    public function __construct(
        private ProjectRepository $projects,
        private UniversityRepository $universities,
        private AuditLogService $auditLog,
        private NotificationService $notifications,
        private AIAnalysisRepository $aiAnalysis,
        private FacultyRepository $faculties
    ) {
    }

    /** يحوّل مستخدم جامعة (users.id) لـ universities.id بتاعته. */
    public function universityIdForUser($userId): ?int
    {
        $university = $this->universities->findByUserId($userId);
        return $university?->id;
    }

    /** يحوّل مستخدم بورتال كلية (migration 106) لـ faculties.id بتاعته. */
    public function facultyIdForUser($userId): ?int
    {
        $faculty = $this->faculties->findByUserId($userId);
        return $faculty?->id;
    }

    /**
     * طابور اعتماد كامل لجامعة، بنفس شكل الـ view القديمة. مشروع draft
     * لسه الطالب مقدموش مستبعد؛ draft ناتج عن "طلب تعديلات" متضمّن
     * وموسوم كده.
     */
    public function queueForUniversity($universityId): array
    {
        $rows = $this->projects->forUniversityWithOwner($universityId);

        $queue = [];
        foreach ($rows as $r) {
            $lastDecision = in_array($r['status'], ['draft', 'submitted', 'published', 'rejected'], true)
                ? $this->lastDecision((int) $r['id'])
                : null;

            if ($r['status'] === 'draft' && $lastDecision !== 'changes_requested') {
                continue; // draft عمره ما اتقدم — لسه مش شغل الجامعة
            }

            $queue[] = $this->toQueueRow($r);
        }

        return $queue;
    }

    public function approve(string $uuid, $universityId, $reviewerId, ?string $comments = null, bool $aiAcknowledged = false): bool
    {
        $project = $this->projects->findForUniversityByUuid($uuid, $universityId);
        if (!$project || $project->status !== 'submitted') {
            return false;
        }

        $snapshot = $this->aiOutputSnapshot((int) $project->id);
        if ($snapshot !== null && !$aiAcknowledged) {
            return false;
        }

        $ok = $this->projects->updateStatusForUniversity($uuid, $universityId, 'published', [
            'published_at' => now(),
        ]);

        if ($ok) {
            $this->recordDecision((int) $project->id, $reviewerId, 'approved', $comments, $aiAcknowledged, $snapshot);
            Log::info('Project approved by university', ['project_id' => $project->id, 'university_id' => $universityId]);

            $title = $project->title_en ?: $project->title_ar;
            $this->notifications->notify(
                $project->owner_id,
                'project_approved',
                "\"{$title}\" was approved and published",
                $comments,
                '/student/projects/' . $project->uuid
            );
        }

        return $ok;
    }

    public function reject(string $uuid, $universityId, $reviewerId, ?string $comments = null, bool $aiAcknowledged = false): bool
    {
        $project = $this->projects->findForUniversityByUuid($uuid, $universityId);
        if (!$project || $project->status !== 'submitted') {
            return false;
        }

        $snapshot = $this->aiOutputSnapshot((int) $project->id);
        if ($snapshot !== null && !$aiAcknowledged) {
            return false;
        }

        $ok = $this->projects->updateStatusForUniversity($uuid, $universityId, 'rejected');

        if ($ok) {
            $this->recordDecision((int) $project->id, $reviewerId, 'rejected', $comments, $aiAcknowledged, $snapshot);
            Log::info('Project rejected by university', ['project_id' => $project->id, 'university_id' => $universityId]);

            $title = $project->title_en ?: $project->title_ar;
            $this->notifications->notify(
                $project->owner_id,
                'project_rejected',
                "\"{$title}\" was rejected",
                $comments,
                '/student/projects/' . $project->uuid
            );
        }

        return $ok;
    }

    public function requestChanges(string $uuid, $universityId, $reviewerId, ?string $comments = null, bool $aiAcknowledged = false): bool
    {
        $project = $this->projects->findForUniversityByUuid($uuid, $universityId);
        if (!$project || $project->status !== 'submitted') {
            return false;
        }

        $snapshot = $this->aiOutputSnapshot((int) $project->id);
        if ($snapshot !== null && !$aiAcknowledged) {
            return false;
        }

        // يرجع للطالب draft عشان يعدل ويعيد التقديم.
        $ok = $this->projects->updateStatusForUniversity($uuid, $universityId, 'draft');

        if ($ok) {
            $this->recordDecision((int) $project->id, $reviewerId, 'changes_requested', $comments, $aiAcknowledged, $snapshot);
            Log::info('University requested changes', ['project_id' => $project->id, 'university_id' => $universityId]);

            $title = $project->title_en ?: $project->title_ar;
            $this->notifications->notify(
                $project->owner_id,
                'project_changes_requested',
                "Changes requested on \"{$title}\"",
                $comments,
                '/student/projects/' . $project->uuid . '/edit'
            );
        }

        return $ok;
    }

    // -- Faculty (بند 10): نفس الـ workflow بالظبط، مقيّد بكلية واحدة عبر
    // ProjectRepository::forFacultyWithOwner()/findForFacultyByUuid()/
    // updateStatusForFaculty() بدل نسخ الجامعة. ميثودز منفصلة (مش
    // parameter زيادة على اللي فوق) عشان bug في مسار الكلية عمره ما يوسّع
    // نطاق مراجع جامعة بالغلط. --------------------------------------------

    /** زي queueForUniversity()، مقيّدة بكلية واحدة. */
    public function queueForFaculty($facultyId): array
    {
        $rows = $this->projects->forFacultyWithOwner($facultyId);

        $queue = [];
        foreach ($rows as $r) {
            $lastDecision = in_array($r['status'], ['draft', 'submitted', 'published', 'rejected'], true)
                ? $this->lastDecision((int) $r['id'])
                : null;

            if ($r['status'] === 'draft' && $lastDecision !== 'changes_requested') {
                continue;
            }

            $queue[] = $this->toQueueRow($r);
        }

        return $queue;
    }

    public function approveForFaculty(string $uuid, $facultyId, $reviewerId, ?string $comments = null, bool $aiAcknowledged = false): bool
    {
        $project = $this->projects->findForFacultyByUuid($uuid, $facultyId);
        if (!$project || $project->status !== 'submitted') {
            return false;
        }

        $snapshot = $this->aiOutputSnapshot((int) $project->id);
        if ($snapshot !== null && !$aiAcknowledged) {
            return false;
        }

        $ok = $this->projects->updateStatusForFaculty($uuid, $facultyId, 'published', [
            'published_at' => now(),
        ]);

        if ($ok) {
            $this->recordDecision((int) $project->id, $reviewerId, 'approved', $comments, $aiAcknowledged, $snapshot);
            Log::info('Project approved by faculty', ['project_id' => $project->id, 'faculty_id' => $facultyId]);

            $title = $project->title_en ?: $project->title_ar;
            $this->notifications->notify(
                $project->owner_id,
                'project_approved',
                "\"{$title}\" was approved and published",
                $comments,
                '/student/projects/' . $project->uuid
            );
        }

        return $ok;
    }

    public function rejectForFaculty(string $uuid, $facultyId, $reviewerId, ?string $comments = null, bool $aiAcknowledged = false): bool
    {
        $project = $this->projects->findForFacultyByUuid($uuid, $facultyId);
        if (!$project || $project->status !== 'submitted') {
            return false;
        }

        $snapshot = $this->aiOutputSnapshot((int) $project->id);
        if ($snapshot !== null && !$aiAcknowledged) {
            return false;
        }

        $ok = $this->projects->updateStatusForFaculty($uuid, $facultyId, 'rejected');

        if ($ok) {
            $this->recordDecision((int) $project->id, $reviewerId, 'rejected', $comments, $aiAcknowledged, $snapshot);
            Log::info('Project rejected by faculty', ['project_id' => $project->id, 'faculty_id' => $facultyId]);

            $title = $project->title_en ?: $project->title_ar;
            $this->notifications->notify(
                $project->owner_id,
                'project_rejected',
                "\"{$title}\" was rejected",
                $comments,
                '/student/projects/' . $project->uuid
            );
        }

        return $ok;
    }

    public function requestChangesForFaculty(string $uuid, $facultyId, $reviewerId, ?string $comments = null, bool $aiAcknowledged = false): bool
    {
        $project = $this->projects->findForFacultyByUuid($uuid, $facultyId);
        if (!$project || $project->status !== 'submitted') {
            return false;
        }

        $snapshot = $this->aiOutputSnapshot((int) $project->id);
        if ($snapshot !== null && !$aiAcknowledged) {
            return false;
        }

        $ok = $this->projects->updateStatusForFaculty($uuid, $facultyId, 'draft');

        if ($ok) {
            $this->recordDecision((int) $project->id, $reviewerId, 'changes_requested', $comments, $aiAcknowledged, $snapshot);
            Log::info('Faculty requested changes', ['project_id' => $project->id, 'faculty_id' => $facultyId]);

            $title = $project->title_en ?: $project->title_ar;
            $this->notifications->notify(
                $project->owner_id,
                'project_changes_requested',
                "Changes requested on \"{$title}\"",
                $comments,
                '/student/projects/' . $project->uuid . '/edit'
            );
        }

        return $ok;
    }

    // -- Academic staff (doctor / TA): قرار على مشروع داخل نطاقهم ------------

    /**
     * قرار عضو هيئة تدريس على مشروع 'submitted'. الفحص إن المشروع داخل نطاقه
     * بيتم قبل النداء (AcademicStaffProjectService). نفس سلوك اعتماد الجامعة
     * (published / rejected / draft + إشعار الطالب + سجل project_approvals).
     * @param string $action approve|reject|request_changes
     */
    public function decideAsStaff(string $uuid, $reviewerId, string $action, ?string $comments, bool $aiAcknowledged = false): bool
    {
        $project = $this->projects->findByUuid($uuid);
        if (!$project || $project->status !== 'submitted') {
            return false;
        }

        $snapshot = $this->aiOutputSnapshot((int) $project->id);
        if ($snapshot !== null && !$aiAcknowledged) {
            return false;
        }

        [$newStatus, $decision, $type, $extra] = match ($action) {
            'approve'         => ['published', 'approved', 'project_approved', ['published_at' => now()->toDateTimeString()]],
            'reject'          => ['rejected', 'rejected', 'project_rejected', []],
            'request_changes' => ['draft', 'changes_requested', 'project_changes_requested', []],
            default           => [null, null, null, []],
        };
        if ($newStatus === null) {
            return false;
        }

        $ok = $this->projects->updateStatusByUuid($uuid, $newStatus, $extra);
        if (!$ok) {
            return false;
        }

        $this->recordDecision((int) $project->id, $reviewerId, $decision, $comments, $aiAcknowledged, $snapshot);
        Log::info('Project decision by academic staff', ['project_id' => $project->id, 'action' => $action]);

        $title = $project->title_en ?: $project->title_ar;
        $headline = match ($action) {
            'approve' => "\"{$title}\" was approved and published",
            'reject'  => "\"{$title}\" was rejected",
            default   => "Changes requested on \"{$title}\"",
        };
        $this->notifications->notify(
            $project->owner_id,
            $type,
            $headline,
            $comments,
            '/student/projects/' . $project->uuid . ($action === 'request_changes' ? '/edit' : '')
        );

        return true;
    }

    private function recordDecision(int $projectId, $reviewerId, string $decision, ?string $comments, bool $aiAcknowledged = false, ?array $aiSnapshot = null): void
    {
        ProjectApproval::create([
            'project_id'  => $projectId,
            'reviewer_id' => $reviewerId,
            'stage'       => 'university_review',
            'decision'    => $decision,
            'comments'    => $comments,
            'decided_at'  => now(),
            'ai_output_acknowledged' => $aiSnapshot !== null && $aiAcknowledged,
            'ai_output_snapshot'     => $aiSnapshot !== null ? json_encode($aiSnapshot, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /**
     * درجة الجاهزية/التصنيف التلقائي لمشروع، بالظبط زي ما مراجع شايفها
     * دلوقتي في الطابور — null لو الاتنين لسه ماتحسبوش. بتتجمّد جوه
     * project_approvals وقت القرار (شوف recordDecision()) مش بتترجع.
     */
    private function aiOutputSnapshot(int $projectId): ?array
    {
        $readiness = $this->aiAnalysis->readinessFor($projectId);
        $classification = $this->aiAnalysis->classificationFor($projectId);

        if ($readiness === null && $classification === null) {
            return null;
        }

        return [
            'readiness_score'           => $readiness !== null ? (float) $readiness->overall_score : null,
            'predicted_category'        => $classification->predicted_category ?? null,
            'classification_confidence' => $classification !== null ? (float) $classification->confidence : null,
            'captured_at'               => now()->toDateTimeString(),
        ];
    }

    /**
     * true لو المشروع ده عنده مؤشرات ذكاء اصطناعي المراجع لازم يأكّد إنه
     * شافها قبل ما approve()/reject()/requestChanges() تقبل قرار عليه.
     * الكولر بينادي دي مقدّمًا عشان يعرض رسالة خطأ محددة بدل رسالة عامة.
     */
    public function requiresAiAcknowledgment(string $uuid): bool
    {
        $project = $this->projects->findByUuid($uuid);
        return $project !== null && $this->aiOutputSnapshot((int) $project->id) !== null;
    }

    // -- Admin: إشراف على مستوى المنصة كلها + override استثنائي --------------

    /**
     * كل مشروع عبر كل الجامعات وصل لمرحلة مراجعة الجامعة على الأقل، بالشكل
     * اللي جدول Admin oversight محتاجه. إشراف قراءة-فقط — القرار العادي
     * فاضل عند الجامعة (شوف docblock الكلاس)؛ الأدمن بيتصرف بس عبر
     * override() تحت.
     * @return array<int,array<string,mixed>>
     */
    public function platformOversight(): array
    {
        return $this->shapeOversightRows($this->projects->forAdminOversight());
    }

    /**
     * نسخة مرقّمة صفحات من platformOversight()، عشان صفحة Admin > Approval
     * Workflows تحمّل صفحة واحدة (+ إجمالي رخيص) بدل الطابور كله.
     * @return array{rows: array<int,array<string,mixed>>, total: int}
     */
    public function platformOversightPaginated(string $search, int $page, int $perPage): array
    {
        $result = $this->projects->paginateAdminOversight($search, $page, $perPage);
        return ['rows' => $this->shapeOversightRows($result['rows']), 'total' => $result['total']];
    }

    /** عدادات كروت الإحصاء الرخيصة، من غير ما تحمّل صفوف الطابور. */
    public function platformOversightCounts(): array
    {
        return $this->projects->countAdminOversight();
    }

    private function shapeOversightRows(array $rows): array
    {
        $uiStatusMap = [
            'submitted' => 'pending',
            'approved'  => 'approved',
            'published' => 'approved',
            'rejected'  => 'rejected',
        ];

        return array_map(function ($r) use ($uiStatusMap) {
            $r = (array) $r;
            $title = $r['title_en'] ?: $r['title_ar'];
            return [
                'id'         => $r['uuid'],
                'title'      => ['en' => $r['title_en'] ?: $title, 'ar' => $r['title_ar'] ?: $title],
                'university' => [
                    'en' => $r['university_name_en'] ?: '—',
                    'ar' => $r['university_name_ar'] ?: '—',
                ],
                'status'     => $uiStatusMap[$r['status']] ?? 'pending',
                'rawStatus'  => $r['status'],
                'decided'    => $r['published_at'] ? date('Y-m-d', strtotime((string) $r['published_at'])) : '—',
            ];
        }, $rows);
    }

    /**
     * تجاوز استثنائي من الأدمن لحالة مشروع، بيتخطى مسار مراجعة الجامعة/
     * الكلية العادي. دايمًا مسجّل في project_approvals (stage=admin_review)
     * وفي سجل التدقيق العام، ودايمًا محتاج سبب مكتوب لأنه بيتخطى قرار
     * بورتال تاني.
     */
    public function override(string $uuid, $adminId, string $newStatus, string $comments): bool
    {
        if (!in_array($newStatus, ['published', 'rejected', 'draft'], true) || trim($comments) === '') {
            return false;
        }

        $project = $this->projects->findByUuid($uuid);
        if (!$project) {
            return false;
        }

        $oldStatus = $project->status;
        $extra = $newStatus === 'published' ? ['published_at' => now()->toDateTimeString()] : [];
        $ok = $this->projects->updateStatusByUuid($uuid, $newStatus, $extra);

        if ($ok) {
            $decision = $newStatus === 'published' ? 'approved' : ($newStatus === 'rejected' ? 'rejected' : 'changes_requested');
            $snapshot = $this->aiOutputSnapshot((int) $project->id);
            ProjectApproval::create([
                'project_id'  => (int) $project->id,
                'reviewer_id' => $adminId,
                'stage'       => 'admin_review',
                'decision'    => $decision,
                'comments'    => $comments,
                'decided_at'  => now()->toDateTimeString(),
                // السبب المكتوب الإلزامي (trim($comments) فوق) هو نفسه دليل
                // المراجعة البشرية هنا — من غير checkbox منفصل، بس مؤشرات
                // الذكاء الاصطناعي (لو موجودة) بتتحفظ بالسنابشوت برضو لنفس
                // سبب سجل التدقيق زي recordDecision().
                'ai_output_acknowledged' => $snapshot !== null ? 1 : 0,
                'ai_output_snapshot'     => $snapshot !== null ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null,
            ]);

            $this->auditLog->record(
                $adminId,
                'project.admin_override',
                'Project',
                (int) $project->id,
                ['status' => $oldStatus],
                ['status' => $newStatus, 'reason' => $comments]
            );

            Log::info('Admin overrode project status', ['project_id' => $project->id, 'from' => $oldStatus, 'to' => $newStatus]);
        }

        return $ok;
    }

    /**
     * آخر قرار مراجع على مشروع، لبانر التغذية الراجعة اللي بيشوفه الطالب
     * (سبب الرفض/التعديلات المطلوبة قبل ما يعيد التقديم) — كان القرار
     * بيتسجل (recordDecision()) بس عمره ما كان بيرجع للطالب. مقيّدة
     * بالملكية: بس صاحب المشروع نفسه يقدر يشوفها.
     * @return array{decision:string,comments:?string,decided_at:string}|null
     */
    public function latestDecisionForOwner(string $uuid, $ownerId): ?array
    {
        $project = $this->projects->findOwnedByUuid($uuid, $ownerId);
        if (!$project) {
            return null;
        }
        $row = ProjectApproval::where('project_id', $project->id)->orderByDesc('created_at')->first();
        if (!$row) {
            return null;
        }
        return [
            'decision'   => $row->decision,
            'comments'   => $row->comments,
            'decided_at' => $row->decided_at,
        ];
    }

    private function lastDecision(int $projectId): ?string
    {
        $row = ProjectApproval::where('project_id', $projectId)->orderByDesc('created_at')->first();
        return $row->decision ?? null;
    }

    private function toQueueRow(array $r): array
    {
        $title = $r['title_en'] ?: $r['title_ar'];

        $uiStatusMap = [
            'submitted' => 'pending',
            'published' => 'approved',
            'rejected'  => 'rejected',
            'draft'     => 'changes_requested', // معمول الوصول لها هنا بس عبر الشرط فوق
        ];

        $readiness = $this->aiAnalysis->readinessFor((int) $r['id']);
        $classification = $this->aiAnalysis->classificationFor((int) $r['id']);

        return [
            'id'         => $r['uuid'],
            'title'      => ['en' => $r['title_en'] ?: $title, 'ar' => $r['title_ar'] ?: $title],
            'student'    => ['en' => $r['owner_name'], 'ar' => $r['owner_name']],
            'faculty'    => ['en' => $r['owner_faculty'] ?: '—', 'ar' => $r['owner_faculty'] ?: '—'],
            'supervisor' => ['en' => '—', 'ar' => '—'], // تعيين المشرف: لسه مش متعمول له موديل
            'score'      => $readiness !== null ? (float) $readiness->overall_score : null,
            'category'   => $r['category'] ?: null,
            'predictedCategory'        => $classification->predicted_category ?? null,
            'classificationConfidence' => $classification !== null ? (float) $classification->confidence : null,
            'submitted'  => $r['created_at'] ? date('Y-m-d', strtotime((string) $r['created_at'])) : '—',
            'status'     => $uiStatusMap[$r['status']] ?? 'pending',
        ];
    }
}
