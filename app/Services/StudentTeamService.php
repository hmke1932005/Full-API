<?php

namespace App\Services;

use App\Models\ProjectTeamMember;
use App\Repositories\ProjectRepository;
use App\Repositories\ProjectTeamMemberRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Log;

/**
 * منقولة بالكامل من app/Services/StudentTeamService.php القديمة — بند
 * 11. طريقتين لإضافة عضو فريق (قرار منتج، الاتنين مدعومين):
 *   - inviteMember(): دعوة بالإيميل، بتتحل بس مع حساب منصة موجود فعلًا
 *     (نفس سلوك ResearchProjectService::inviteMember() — status بتبدأ
 *     'pending' لحد ما المدعو يقبل).
 *   - addManualMember(): اسم + سنة دراسية + رقم طالب، من غير الحاجة
 *     لحساب — بتتسجل 'accepted' فورًا لأنه مفيش حد تاني محتاج يأكّدها.
 *
 * نفس شكل ProjectPublishingService/ProjectLinkService بالظبط ("الكنترولر
 * عمره ما يلمس الـ repository/الموديل مباشرة").
 */
class StudentTeamService
{
    /** بيخلي قائمة فريق المشروع مقروءة — نفس منطق حد اللينكات في ProjectLinkService. */
    private const MAX_TEAM_MEMBERS = 10;

    /**
     * الأدوار اللي الطالب يقدر يختارها لعضو مضاف يدويًا (migration
     * 131/135). 'supervisor' مستبعد عمدًا هنا — الدكتور/المشرف الوحيد
     * فاضل على projects.supervisor_name، عبر حقله المخصص، عشان يفضل
     * مكان واحد بس بيقود خطوة مراجعة المشرف. القايمة دي للزملاء وباقي
     * المعارف الأكاديمية (معيد/TA، متعاونين، ...) المسجلين جنبهم.
     */
    public const MANUAL_ROLES = [
        'student_member', 'teaching_assistant', 'principal_investigator',
        'professor', 'external_collaborator', 'collaborator',
    ];

    public function __construct(
        private ProjectRepository $projects,
        private ProjectTeamMemberRepository $team,
        private UserRepository $users,
        private NotificationService $notifications,
        private MailService $mail,
        private AuditLogService $auditLog
    ) {
    }

    /** @return ProjectTeamMember[] */
    public function listForOwner(string $projectUuid, $ownerId): array
    {
        $project = $this->projects->findOwnedByUuid($projectUuid, $ownerId);
        if (!$project) {
            return [];
        }
        return $this->team->forProject($project->id);
    }

    /**
     * بتدعو مستخدم منصة موجود (بالإيميل) لفريق المشروع.
     * @throws \RuntimeException رسالتها آمنة تتعرض للمستخدم
     */
    public function inviteMember(string $projectUuid, $ownerId, string $email, string $locale = 'ar'): ProjectTeamMember
    {
        $project = $this->projects->findOwnedByUuid($projectUuid, $ownerId);
        if (!$project) {
            throw new \RuntimeException('Project not found.');
        }

        $this->assertRoom($project->id);

        $email = mb_strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException($locale === 'ar' ? 'بريد إلكتروني غير صالح.' : 'Invalid email address.');
        }

        if ($this->team->findByProjectAndEmail($project->id, $email)) {
            throw new \RuntimeException($locale === 'ar'
                ? 'هذا البريد الإلكتروني مضاف بالفعل على هذا المشروع.'
                : 'This email is already on this project\'s team.');
        }

        $invitedUser = $this->users->findByEmail($email);
        if (!$invitedUser) {
            throw new \RuntimeException($locale === 'ar'
                ? 'لا يوجد حساب مسجّل بهذا البريد الإلكتروني على المنصة. اطلب منه/منها إنشاء حساب، أو أضفه/أضفها ببياناته كعضو بدون حساب.'
                : 'No platform account is registered with this email yet. Ask them to sign up, or add them as a member without an account.');
        }

        $member = $this->team->create([
            'project_id'    => $project->id,
            'user_id'       => $invitedUser->id,
            'invited_email' => $email,
            'role'          => 'student_member',
            'status'        => 'pending',
            'invited_by'    => $ownerId,
            'invited_at'    => now(),
        ]);

        $projectTitle = $project->title_en ?: $project->title_ar;
        $title = $locale === 'ar' ? 'دعوة للانضمام لفريق مشروع' : 'Invitation to join a project team';
        $body = $locale === 'ar'
            ? "دعاك زميلك للانضمام كعضو فريق على مشروع \"{$projectTitle}\"."
            : "You've been invited as a team member on the project \"{$projectTitle}\".";
        $linkUrl = '/team-invites/' . $member->id;

        $this->notifications->notify($invitedUser->id, 'team_invite', $title, $body, $linkUrl);
        $this->mail->sendNotificationEmail($invitedUser->email, $invitedUser->full_name ?? '', $title, $body, $linkUrl, $locale);

        $this->auditLog->record($ownerId, 'student.team_invite', 'Project', $project->id, null, ['email' => $email]);

        return $member;
    }

    /**
     * تسجيل زميل معندوش حساب منصة: اسم + سنة دراسية + رقم طالب. بتتقبل
     * فورًا — مفيش حد تاني محتاج يأكّد سجل نصي عادي.
     * @throws \RuntimeException رسالتها آمنة تتعرض للمستخدم
     */
    public function addManualMember(
        string $projectUuid,
        $ownerId,
        string $name,
        ?int $academicYear = null,
        ?string $studentNumber = null,
        string $locale = 'ar',
        string $role = 'student_member'
    ): ProjectTeamMember {
        $project = $this->projects->findOwnedByUuid($projectUuid, $ownerId);
        if (!$project) {
            throw new \RuntimeException('Project not found.');
        }

        $this->assertRoom($project->id);

        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException($locale === 'ar' ? 'اسم العضو مطلوب.' : "Member's name is required.");
        }
        if (mb_strlen($name) > 190) {
            $name = mb_substr($name, 0, 190);
        }

        if (!in_array($role, self::MANUAL_ROLES, true)) {
            $role = 'student_member';
        }

        $studentNumber = $studentNumber !== null ? trim($studentNumber) : null;
        $studentNumber = ($studentNumber !== null && $studentNumber !== '') ? mb_substr($studentNumber, 0, 50) : null;

        if ($academicYear !== null && ($academicYear < 1 || $academicYear > 10)) {
            $academicYear = null;
        }

        $member = $this->team->create([
            'project_id'     => $project->id,
            'member_name'    => $name,
            'academic_year'  => $academicYear,
            'student_number' => $studentNumber,
            'role'           => $role,
            'status'         => 'accepted',
            'invited_by'     => $ownerId,
            'invited_at'     => now(),
            'responded_at'   => now(),
        ]);

        $this->auditLog->record($ownerId, 'student.team_add_manual', 'Project', $project->id, null, ['name' => $name]);

        Log::info('Manual team member added', ['project_id' => $project->id, 'member_id' => $member->id]);

        return $member;
    }

    public function removeMember(string $projectUuid, $ownerId, $memberId): bool
    {
        $project = $this->projects->findOwnedByUuid($projectUuid, $ownerId);
        if (!$project) {
            return false;
        }
        $member = $this->team->findForProject($memberId, $project->id);
        if (!$member) {
            return false;
        }
        $deleted = $this->team->deleteForProject($memberId, $project->id);
        if ($deleted) {
            $this->auditLog->record($ownerId, 'student.team_remove', 'Project', $project->id, null, ['member_id' => $memberId]);
        }
        return $deleted;
    }

    private function assertRoom(int $projectId): void
    {
        if (count($this->team->forProject($projectId)) >= self::MAX_TEAM_MEMBERS) {
            throw new \RuntimeException('A project can have at most ' . self::MAX_TEAM_MEMBERS . ' team members.');
        }
    }
}
