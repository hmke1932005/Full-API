<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTeamMember;
use App\Support\PersonName;
use Illuminate\Support\Facades\DB;

/**
 * مكان واحد لـ: (1) ربط المشرف اللي الطالب كتب اسمه بحساب الدكتور الحقيقي (user_id)،
 * (2) إشعار المراجعين (دكتور/معيد/كلية/جامعة) لما المشروع يتقدّم، (3) إشعار الفريق
 * بكل قرار أو تقييم — مش المالك بس.
 */
class ProjectNotifier
{
    /** أدوار الفريق اللي معناها "عضو هيئة تدريس مرتبط بالمشروع". */
    private const STAFF_ROLES = ['supervisor', 'professor', 'principal_investigator', 'teaching_assistant'];

    public function __construct(private NotificationService $notifications)
    {
    }

    private function title(Project $project): string
    {
        return (string) ($project->title_ar ?: $project->title_en);
    }

    private function universityId(Project $project)
    {
        return $project->university_id
            ?: DB::table('students')->where('user_id', $project->owner_id)->value('university_id');
    }

    /**
     * يدوّر على الدكتور بالاسم المكتوب في supervisor_name (بعد توحيد الكتابة) جوه نفس الجامعة،
     * ولو لقى واحد بس بيربطه بصف فريق accepted بدور supervisor عشان يتربط بالـ user_id.
     * لو الاسم بيطابق أكتر من حد، مبنخمنش.
     * @return int|null user_id للدكتور المربوط
     */
    public function linkSupervisor(?Project $project): ?int
    {
        if (!$project || trim((string) $project->supervisor_name) === '') {
            return null;
        }
        $uni = $this->universityId($project);
        if (!$uni) {
            return null;
        }

        $staff = DB::table('academic_staff as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.university_id', $uni)->where('a.status', 'active')->whereNull('u.deleted_at')
            ->get(['a.user_id', 'u.full_name', 'u.email']);

        $typed = PersonName::normalize($project->supervisor_name);
        $exact = $staff->filter(fn ($s) => PersonName::normalize($s->full_name) === $typed)->values();
        $picked = $exact->count() === 1 ? $exact[0] : null;
        if ($picked === null && $exact->count() === 0) {
            $partial = $staff->filter(fn ($s) => PersonName::matches($project->supervisor_name, $s->full_name))->values();
            $picked = $partial->count() === 1 ? $partial[0] : null;
        }
        if ($picked === null) {
            return null;
        }

        $existing = ProjectTeamMember::where('project_id', $project->id)->where('user_id', $picked->user_id)->first();
        $newlyLinked = false;
        if (!$existing) {
            ProjectTeamMember::create([
                'project_id'    => $project->id,
                'user_id'       => $picked->user_id,
                'invited_email' => mb_strtolower((string) $picked->email),
                'role'          => 'supervisor',
                'status'        => 'accepted',
                'invited_by'    => $project->owner_id,
                'invited_at'    => now(),
                'responded_at'  => now(),
            ]);
            $newlyLinked = true;
        } elseif (in_array($existing->status, ['rejected', 'removed'], true)) {
            $existing->fill(['status' => 'accepted', 'role' => 'supervisor', 'responded_at' => now()])->save();
            $newlyLinked = true;
        }

        // لو المشروع متقدّم فعلًا والدكتور لسه متربط دلوقتي، يتبلّغ.
        if ($newlyLinked && in_array($project->status, ['submitted', 'under_review'], true)) {
            $this->notifications->notify(
                $picked->user_id, 'project_submitted',
                'مشروع جديد مطلوب مراجعته',
                '"' . $this->title($project) . '" تم تقديمه وأنت المشرف عليه.',
                '/academic-staff/projects/' . $project->uuid
            );
        }

        return (int) $picked->user_id;
    }

    /** إشعار كل الأطراف لما المشروع يتقدّم: المشرف/المعيد، الكلية، الجامعة، وباقي الفريق. */
    public function notifySubmitted(?Project $project): void
    {
        if (!$project) {
            return;
        }
        $this->linkSupervisor($project);

        $title = $this->title($project);
        $sent = [(int) $project->owner_id => true];
        $send = function ($userId, string $body, string $link) use (&$sent) {
            $userId = (int) $userId;
            if (!$userId || isset($sent[$userId])) {
                return;
            }
            $sent[$userId] = true;
            $this->notifications->notify($userId, 'project_submitted', 'مشروع جديد مقدّم للمراجعة', $body, $link);
        };

        // دكتور/معيد مرتبطين عبر الفريق (بالحساب)
        $staffIds = ProjectTeamMember::where('project_id', $project->id)->where('status', 'accepted')
            ->whereIn('role', self::STAFF_ROLES)->whereNotNull('user_id')->pluck('user_id')->all();
        foreach ($staffIds as $uid) {
            $send($uid, "\"{$title}\" تم تقديمه وبانتظار مراجعتك.", '/academic-staff/projects/' . $project->uuid);
        }

        // كلية الطالب
        $facultyId = DB::table('students')->where('user_id', $project->owner_id)->value('faculty_id');
        if ($facultyId) {
            $send(DB::table('faculties')->where('id', $facultyId)->value('user_id'), "\"{$title}\" تم تقديمه لاعتمادكم.", '/faculty/approvals');
        }

        // الجامعة
        $uni = $this->universityId($project);
        if ($uni) {
            $send(DB::table('universities')->where('id', $uni)->value('user_id'), "\"{$title}\" تم تقديمه لاعتمادكم.", '/university/approvals');
        }

        // باقي أعضاء الفريق من الطلبة
        $memberIds = ProjectTeamMember::where('project_id', $project->id)->where('status', 'accepted')
            ->whereNotIn('role', self::STAFF_ROLES)->whereNotNull('user_id')->pluck('user_id')->all();
        foreach ($memberIds as $uid) {
            $send($uid, "تم تقديم المشروع \"{$title}\" للمراجعة.", '/student/projects/' . $project->uuid);
        }
    }

    /**
     * إشعار المالك + كل أعضاء الفريق اللي ليهم حساب (طلبة وكمان دكاترة/معيدين) بقرار أو تقييم.
     * مبيبعتش للي اتّخد القرار بنفسه. لينك التعديل (/edit) للمالك بس.
     */
    public function notifyTeam(Project $project, string $type, string $title, ?string $body, string $link, $excludeUserId = null): void
    {
        $ids = [(int) $project->owner_id => $link];
        $plain = preg_replace('#/edit$#', '', $link);
        $members = ProjectTeamMember::where('project_id', $project->id)->where('status', 'accepted')
            ->whereNotNull('user_id')->get(['user_id', 'role']);
        foreach ($members as $m) {
            $uid = (int) $m->user_id;
            if (isset($ids[$uid])) {
                continue;
            }
            $ids[$uid] = in_array($m->role, self::STAFF_ROLES, true) ? '/academic-staff/projects/' . $project->uuid : $plain;
        }
        foreach ($ids as $uid => $url) {
            if ($excludeUserId !== null && (int) $excludeUserId === (int) $uid) {
                continue;
            }
            $this->notifications->notify($uid, $type, $title, $body, $url);
        }
    }
}
