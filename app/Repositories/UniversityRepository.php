<?php

namespace App\Repositories;

use App\Models\University;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * منقولة من app/Repositories/UniversityRepository.php القديمة (Core\Database
 * -> DB facade). بند 3 بيستخدم منها بس المتود اللي فعلًا وراها استعمال في
 * UniversitiesApiController — القديمة فيها ميثودز تانية (allForRegistration،
 * allWithStats، countStats، countByStatus، innovationHubPatents/...) خاصة
 * بـ Admin Dashboard/تسجيل الطلاب (بنود لاحقة)، مش منقولة هنا عمدًا؛ هتتضاف
 * لما بندها ييجي بدل تكرار كود مالوش مستهلك دلوقتي.
 */
class UniversityRepository
{
    public function findByUserId($userId): ?University
    {
        return University::where('user_id', $userId)->first();
    }

    /**
     * نفس findByUserId()، لكن بتعمل self-heal لحساب اتسجل قبل ما التسجيل
     * يبقى بيعمل provision لصف universities تلقائي — صفحات Settings/
     * Verification بتنادي دي بدل findByUserId() عشان محدش يواجه "profile
     * not found" أبدًا.
     */
    public function getOrCreate($userId, string $fallbackName = ''): University
    {
        $university = $this->findByUserId($userId);
        if ($university) {
            return $university;
        }

        return University::create([
            'user_id'          => $userId,
            'official_name_ar' => $fallbackName,
            'official_name_en' => $fallbackName,
            'country'          => '',
        ]);
    }

    /** كل الجامعات، مرتبة بالاسم — القايمة اللي فورم طلب شراكة الشركة (بند 6) بيعرضها. @return University[] */
    public function allForRegistration(): array
    {
        return University::orderBy('official_name_en')->get()->all();
    }

    public function find($id): ?University
    {
        return University::find($id);
    }

    /**
     * منقولة من UniversityRepository::countStats() القديمة — بند 25
     * (AdminUniversitiesApiController::index() -> 'counts'، كروت إحصائيات
     * صفحة Universities). عدّات رخيصة بدون تحميل صفوف.
     * @return array{total:int,verified:int,pending:int,total_students:int}
     */
    public function countStats(): array
    {
        $row = DB::table('universities')
            ->selectRaw("COUNT(*) as total,
                SUM(CASE WHEN verification_status = 'verified' THEN 1 ELSE 0 END) as verified,
                SUM(CASE WHEN verification_status = 'pending' THEN 1 ELSE 0 END) as pending")
            ->first();

        return [
            'total'          => (int) ($row->total ?? 0),
            'verified'       => (int) ($row->verified ?? 0),
            'pending'        => (int) ($row->pending ?? 0),
            'total_students' => DB::table('students')->count(),
        ];
    }

    /**
     * كل الجامعات + عدد الطلاب/المشاريع لايف، الأحدث انضمامًا الأول — بند
     * الـ Reports (ReportService::universityOnboardingDigest()) بس، مش
     * فلترة/ترقيم زي paginateWithStats() فوق.
     * @return array<int,array<string,mixed>>
     */
    public function allWithStats(): array
    {
        return DB::table('universities as uni')
            ->select('uni.*')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('students as s')->whereColumn('s.university_id', 'uni.id');
            }, 'students_count')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('projects as p')->whereColumn('p.university_id', 'uni.id');
            }, 'projects_count')
            ->orderByDesc('uni.created_at')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * صفوف الجامعات + عدد الطلاب/المشاريع لايف، مع بحث وفلتر حالة، محدودة
     * بـ LIMIT/OFFSET — عشان صفحة الـ directory ميحملش كل الجامعات مرة واحدة.
     * @return array{rows: array<int,array<string,mixed>>, total: int}
     */
    public function paginateWithStats(string $search, string $status, int $page, int $perPage): array
    {
        $query = DB::table('universities as uni')
            ->select('uni.*')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('students as s')->whereColumn('s.university_id', 'uni.id');
            }, 'students_count')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('projects as p')->whereColumn('p.university_id', 'uni.id');
            }, 'projects_count');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where('uni.official_name_en', 'like', $like)
                    ->orWhere('uni.official_name_ar', 'like', $like)
                    ->orWhere('uni.city', 'like', $like)
                    ->orWhere('uni.country', 'like', $like);
            });
        }
        if ($status !== '') {
            $query->where('uni.verification_status', $status);
        }

        $total = (clone $query)->count();

        $perPage = max(1, $perPage);
        $offset = max(0, $page - 1) * $perPage;
        $rows = $query->orderByDesc('uni.created_at')->limit($perPage)->offset($offset)->get();

        return ['rows' => $rows->map(fn ($r) => (array) $r)->all(), 'total' => $total];
    }

    /**
     * جامعة واحدة + عدد الطلاب/المشاريع المنشورة لايف — شكل صفحات البروفايل
     * العامة (يطابق UniversityRepository::withProfileStats() القديمة).
     * @return array<string,mixed>|null
     */
    public function withProfileStats($id): ?array
    {
        $row = DB::table('universities as uni')
            ->select('uni.*')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('students as s')->whereColumn('s.university_id', 'uni.id');
            }, 'students_count')
            ->selectSub(function ($q) {
                $q->selectRaw('COUNT(*)')->from('projects as p')
                    ->whereColumn('p.university_id', 'uni.id')
                    ->where('p.status', 'published');
            }, 'published_projects_count')
            ->where('uni.id', $id)
            ->first();

        return $row ? (array) $row : null;
    }

    /**
     * تجميع Innovation Hub (براءات اختراع/شراكات) —
     * يطابق innovationHubStats() القديمة حرف بحرف.
     * @return array<string,int>
     */
    public function innovationHubStats($id): array
    {
        $row = DB::selectOne(
            "SELECT
                (SELECT COUNT(*) FROM patents pt INNER JOIN projects p ON p.id = pt.project_id WHERE p.university_id = :id1) AS patents_count",
            ['id1' => $id]
        );
        $row = $row ? (array) $row : [];

        return [
            'patents_count'               => (int) ($row['patents_count'] ?? 0),
        ];
    }

    /** أحدث براءات اختراع الجامعة، لعرض Innovation Hub. @return array<int,array<string,mixed>> */
    public function innovationHubPatents($id, int $limit = 6): array
    {
        return DB::table('patents as pt')
            ->join('projects as p', 'p.id', '=', 'pt.project_id')
            ->where('p.university_id', $id)
            ->select('pt.title', 'pt.status', 'pt.filed_at')
            ->orderByDesc('pt.created_at')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * قرار الأدمن على طلب اعتماد الجامعة. بس pending -> verified/rejected؛
     * جامعة اتقرر فيها قبل كده متتلمسش تاني (idempotent).
     */
    public function decide($id, string $status, $adminId): bool
    {
        $university = University::find($id);
        if (!$university || $university->verification_status !== 'pending') {
            return false;
        }

        $fields = [
            'verification_status' => $status,
            'verified_at'         => now(),
            'verified_by'         => $adminId,
        ];

        // Automatic Re-verification (094): أول دورة اعتماد بتبدأ هنا —
        // المدة بالأيام خاصة بالجامعة لو متسجلة، وإلا الافتراضي العام.
        if ($status === 'verified') {
            $days = (int) ($university->verification_period_days ?: config('verification.period_days', 365));
            $fields['verification_expires_at'] = now()->addDays($days);
            $fields['verification_expiry_notified_at'] = null;
        }

        return $university->fill($fields)->save();
    }

    /** إعدادات إعادة الاعتماد التلقائي (Admin) لجامعة واحدة. null بترجّع للافتراضي العام. */
    public function updateReverificationSettings($id, ?int $periodDays, bool $autoReverifyEnabled): bool
    {
        $university = University::find($id);
        if (!$university) {
            return false;
        }

        return $university->fill([
            'verification_period_days' => $periodDays,
            'auto_reverify_enabled'    => $autoReverifyEnabled,
        ])->save();
    }

    /** حذف نهائي من سوبر أدمن: بيمسح صف الجامعة وبيوقف حساب المستخدم المرتبط (لو موجود). */
    public function delete($id): bool
    {
        $university = University::find($id);
        if (!$university) {
            return false;
        }
        if ($university->user_id) {
            DB::table('users')->where('id', $university->user_id)->update([
                'deleted_at' => now(),
                'status'     => 'banned',
            ]);
        }
        return (bool) $university->delete();
    }

    /** جامعة واحدة بالـ slug العام (migration 137). */
    public function findBySlug(string $slug): ?University
    {
        return University::where('slug', $slug)->first();
    }

    /**
     * البحث اللي وراه routes/faculty/public/{universitySlug}* — بيقبل id
     * رقمي كمان لأي كولر داخلي لسه معاه واحد. جامعة اتسجلت قبل migration
     * 137 (أو الـ slug فاضي لأي سبب) بيتعمل لها backfill أول مرة تتطلب
     * هنا، بنفس اتفاقية self-healing اللي في UniversityRepository الأصلية.
     */
    public function findBySlugOrId(string $identifier): ?University
    {
        $university = $this->findBySlug($identifier);
        if ($university) {
            return $university;
        }

        if (!ctype_digit($identifier)) {
            return null;
        }

        $university = University::find((int) $identifier);
        if (!$university) {
            return null;
        }

        if (empty($university->slug)) {
            $university->slug = $this->generateUniqueSlug($university);
            $university->save();
        }

        return $university;
    }

    private function generateUniqueSlug(University $university): string
    {
        $base = Str::slug((string) ($university->official_name_en ?: $university->official_name_ar));
        if ($base === '') {
            $base = 'university';
        }
        $base = mb_substr($base, 0, 180);

        do {
            $slug = $base . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        } while (University::where('slug', $slug)->exists());

        return $slug;
    }
}
