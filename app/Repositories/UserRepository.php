<?php

namespace App\Repositories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * منقولة جزئيًا من app/Repositories/UserRepository.php القديمة —
 * emailExists()/createWithRole() بس (اللي StudentManagementService::invite()
 * محتاجاها). القديمة فيها كمان createBareWithRole() (لدعوات فريق الشركة)
 * وميثودز تانية كتير خاصة ببنود لاحقة (searchDirectory، إلخ) — مش منقولة
 * هنا عمدًا.
 *
 * provisionRoleProfile() هنا بس فرع 'student' (زي ما invite() فعليًا
 * بيستخدم) — القديمة فيها فروع لكل الأدوار (university)،
 * متكررة أصلًا في RegisterController الحالية لتسجيل الدخول
 * العام؛ هنا مقتصرة على اللي بند 4 محتاجه بدل تكرار سيناريوهات مالهاش
 * استهلاك من هنا.
 */
class UserRepository
{
    public function emailExists(string $email): bool
    {
        return User::where('email', $email)->exists();
    }

    /** يطابق UserRepository::findByEmail() القديمة بالظبط — بند 11 (دعوة عضو فريق بالإيميل) محتاجاها. */
    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    public function createWithRole(array $userData, string $roleSlug): User
    {
        return DB::transaction(function () use ($userData, $roleSlug) {
            $userData['uuid'] = $userData['uuid'] ?? (string) Str::uuid();
            $user = User::create($userData);

            $roleId = Role::where('slug', $roleSlug)->value('id');
            if ($roleId) {
                DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => $roleId]);
            }

            $this->provisionRoleProfile($user->id, $roleSlug);

            return $user;
        });
    }

    private function provisionRoleProfile(int $userId, string $roleSlug): void
    {
        if ($roleSlug === 'student') {
            DB::table('students')->insert(['user_id' => $userId]);
        }
    }

    // -- Account settings: password/2FA/team invites -----------------------

    public function updatePassword($userId, string $passwordHash): void
    {
        DB::table('users')->where('id', $userId)->update(['password_hash' => $passwordHash]);
    }

    /**
     * زي createWithRole() فوق بالظبط — بس مسمّاة زي القديمة (createBareWithRole)
     * عشان توضيح إنها لدعوة حساب "فاضي" (عضو فريق) مش تسجيل ذاتي. مفيش
     * فرع provisionRoleProfile هنا — صف الملف الشخصي بيتعمل provision مرة
     * واحدة بس لصاحب الحساب الأصلي، مش لكل عضو فريق.
     */
    public function createBareWithRole(array $userData, string $roleSlug): User
    {
        return $this->createWithRole($userData, $roleSlug);
    }

    /** يفعّل 2FA بعد تأكيد أول كود — مطابق لـ enableTwoFactor() القديمة. */
    public function enableTwoFactor($userId, string $secret, array $hashedRecoveryCodes): bool
    {
        $user = User::find($userId);
        if (!$user) {
            return false;
        }
        $user->fill([
            'two_factor_enabled'        => true,
            'two_factor_secret'         => $secret,
            'two_factor_confirmed_at'   => now(),
            // موديل User هنا معمول عليه array cast لـ two_factor_recovery_codes
            // (بخلاف القديمة اللي كانت بتخزن JSON string يدوي) — فبنمرر
            // الـ array نفسه، الـ cast هو اللي هيعمل json_encode وقت الحفظ.
            'two_factor_recovery_codes' => $hashedRecoveryCodes,
            // سياسة MFA Requirements (بند 25، Security Portal) — تفعيل 2FA
            // بيغطي المتطلب، فأي عداد grace-period شغال للمستخدم ده بيتصفّر.
            'mfa_grace_started_at'      => null,
        ]);
        return $user->save();
    }

    /** يقفل 2FA — مطابق لـ disableTwoFactor() القديمة. */
    public function disableTwoFactor($userId): bool
    {
        $user = User::find($userId);
        if (!$user) {
            return false;
        }
        $user->fill([
            'two_factor_enabled'        => false,
            'two_factor_secret'         => null,
            'two_factor_confirmed_at'   => null,
            'two_factor_recovery_codes' => null,
        ]);
        return $user->save();
    }

    /**
     * دليل بحث عن مستخدمين (لصندوق "New Message" ولصفحة نتائج بحث الشركة،
     * بند 6) — اسم/إيميل، مستبعد المستخدم نفسه، مدموج بمؤسسته (جامعة/
     * شركة/كلية طالب) في عمود org واحد.
     * @return array<int,array<string,mixed>>
     */
    public function searchDirectory(string $query, $excludeUserId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $q = '%' . $query . '%';

        return DB::table('users as u')
            ->leftJoin('user_roles as ur', 'ur.user_id', '=', 'u.id')
            ->leftJoin('roles as r', 'r.id', '=', 'ur.role_id')
            ->leftJoin('students as s', 's.user_id', '=', 'u.id')
            ->leftJoin('universities as uni', 'uni.user_id', '=', 'u.id')
            ->whereNull('u.deleted_at')
            ->where('u.status', 'active')
            ->where('u.id', '!=', $excludeUserId)
            ->where(function ($w) use ($q) {
                $w->where('u.full_name', 'like', $q)->orWhere('u.email', 'like', $q);
            })
            ->selectRaw(
                'u.id, u.uuid, u.full_name, u.email, u.avatar_path, r.slug AS role,
                 COALESCE(uni.official_name_en, s.faculty) AS org_en,
                 COALESCE(uni.official_name_ar, s.faculty) AS org_ar'
            )
            ->orderBy('u.full_name')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => (array) $r)->all();
    }

    public function findById($userId): ?User
    {
        return User::find($userId);
    }

    /**
     * منقولة جزئيًا من UserRepository::allWithRoles() القديمة — فلتر
     * `role` بس دلوقتي (بند 25 batch 2، لمُلئ dropdown الـ assign في
     * Alerts/Incidents بموظفي الأمن). القديمة فيها فلاتر status/search
     * كمان + أعمدة انتماء (uni/faculty/...) — مش لازمة هنا،
     * الـ dropdown محتاج id/full_name/email/role بس. أول أدور معيّن
     * لكل مستخدم (نفس منطق subquery MIN(assigned_at) القديم).
     * @return array<int,array<string,mixed>> id/uuid/full_name/email/role، بترتيب full_name
     */
    public function allWithRoles(array $filters = []): array
    {
        $query = DB::table('users as u')
            ->leftJoin(DB::raw('(SELECT ur1.user_id, ur1.role_id FROM user_roles ur1
                    WHERE ur1.assigned_at = (SELECT MIN(ur2.assigned_at) FROM user_roles ur2 WHERE ur2.user_id = ur1.user_id)) as ur'), 'ur.user_id', '=', 'u.id')
            ->leftJoin('roles as r', 'r.id', '=', 'ur.role_id')
            ->whereNull('u.deleted_at')
            ->select('u.id', 'u.uuid', 'u.full_name', 'u.email', 'r.slug as role');

        if (!empty($filters['role'])) {
            $query->where('r.slug', $filters['role']);
        }
        if (!empty($filters['status'])) {
            $query->where('u.status', $filters['status']);
        }

        return $query->orderBy('u.full_name')->get()->map(fn ($r) => (array) $r)->all();
    }

    /**
     * منقولة من UserRepository::countByRole() القديمة — عدد المستخدمين
     * (غير المحذوفين) لكل دور، بترتيب roles.id. بند الـ Reports/Analytics
     * بس (AnalyticsService::usersByRoleSeries()) دلوقتي.
     * @return array<int,array{slug:string,name_en:string,name_ar:string,total:int}>
     */
    public function countByRole(): array
    {
        return DB::table('roles as r')
            ->leftJoin('user_roles as ur', 'ur.role_id', '=', 'r.id')
            ->leftJoin('users as u', function ($j) {
                $j->on('u.id', '=', 'ur.user_id')->whereNull('u.deleted_at');
            })
            ->selectRaw('r.slug, r.name_en, r.name_ar, COUNT(ur.user_id) as total')
            ->groupBy('r.id', 'r.slug', 'r.name_en', 'r.name_ar')
            ->orderBy('r.id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * منقولة من UserRepository::monthlySignups() القديمة — سلسلة شهرية
     * كاملة (شهور من غير تسجيلات بترجع 0، مش تتقفز) لآخر $months شهر.
     * بند الـ Reports (ReportService::platformGrowthReport()) بس دلوقتي.
     * @return array<int,array{month:string,total:int}>
     */
    public function monthlySignups(int $months = 6): array
    {
        $months = max(1, $months);
        $rows = DB::table('users')
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as total")
            ->whereNull('deleted_at')
            ->where('created_at', '>=', now()->subMonths($months - 1)->startOfMonth())
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $byMonth = $rows->pluck('total', 'month');

        $series = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $key = now()->subMonths($i)->format('Y-m');
            $series[] = ['month' => $key, 'total' => (int) ($byMonth[$key] ?? 0)];
        }
        return $series;
    }

    // -- Account lockout (Security Policies § Failed Login Policy) — بند 25 batch 3 --

    /** منقولة من UserRepository::incrementFailedLoginAttempts() القديمة بالظبط. @return int العدّاد الجديد بعد الزيادة */
    public function incrementFailedLoginAttempts($userId): int
    {
        DB::table('users')->where('id', $userId)->increment('failed_login_attempts');
        return (int) DB::table('users')->where('id', $userId)->value('failed_login_attempts');
    }

    public function resetFailedLoginAttempts($userId): void
    {
        DB::table('users')->where('id', $userId)->update(['failed_login_attempts' => 0]);
    }

    /** يقفل حساب. $lockedUntil سترينج 'Y-m-d H:i:s'، أو null لو $permanent = true. */
    public function lockAccount($userId, ?string $lockedUntil, bool $permanent, string $reason): void
    {
        DB::table('users')->where('id', $userId)->update([
            'failed_login_attempts' => 0,
            'locked_until'   => $permanent ? null : $lockedUntil,
            'lock_permanent' => $permanent ? 1 : 0,
            'lock_reason'    => $reason,
            'locked_at'      => now(),
            'unlocked_by'    => null,
            'unlocked_at'    => null,
        ]);
    }

    /** بيمسح القفل — auto-unlock (system, $adminUserId = null) أو unlock يدوي من الأدمن. */
    public function unlockAccount($userId, $adminUserId = null): void
    {
        DB::table('users')->where('id', $userId)->update([
            'failed_login_attempts' => 0,
            'locked_until'   => null,
            'lock_permanent' => 0,
            'unlocked_by'    => $adminUserId,
            'unlocked_at'    => now(),
        ]);
    }

    // -- MFA policy grace period (Security Policies § MFA Requirements) — بند 25 batch 3 --

    /** يبدأ/يمسح عدّاد grace period بتاع MFA لمستخدم — مطابق لـ setMfaGraceStart() القديمة. $startedAt سترينج 'Y-m-d H:i:s'، أو null لمسح العدّاد. */
    public function setMfaGraceStart($userId, ?string $startedAt): void
    {
        DB::table('users')->where('id', $userId)->update(['mfa_grace_started_at' => $startedAt]);
    }

    /** @return array<int,array<string,mixed>> كل حساب مقفول حاليًا (دائم، أو locked_until في المستقبل) */
    public function lockedAccounts(): array
    {
        return DB::table('users')
            ->select('id', 'uuid', 'full_name', 'email', 'lock_permanent', 'locked_until', 'lock_reason', 'locked_at')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->where('lock_permanent', 1)
                    ->orWhere(function ($q2) {
                        $q2->whereNotNull('locked_until')->where('locked_until', '>', now());
                    });
            })
            ->orderByDesc('locked_at')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** نفس عمود org المدموج بتاع rolesBaseSelect() القديمة، بس هنا كـ query builder joins بدل raw SQL string. */
    private function rolesWithOrgQuery()
    {
        return DB::table('users as u')
            ->leftJoin(DB::raw('(SELECT ur1.user_id, ur1.role_id FROM user_roles ur1
                    WHERE ur1.assigned_at = (SELECT MIN(ur2.assigned_at) FROM user_roles ur2 WHERE ur2.user_id = ur1.user_id)) as ur'), 'ur.user_id', '=', 'u.id')
            ->leftJoin('roles as r', 'r.id', '=', 'ur.role_id')
            ->leftJoin('students as s', 's.user_id', '=', 'u.id')
            ->leftJoin('universities as uni', 'uni.user_id', '=', 'u.id')
            ->whereNull('u.deleted_at');
    }

    /**
     * منقولة من UserRepository::paginateWithRoles() القديمة — نفس فلاتر
     * buildRoleFilters() (role/status/search)، بس هنا كـ query builder.
     *     * @return array{rows: array<int,array<string,mixed>>, total: int}
     */
    public function paginateWithRoles(array $filters, int $page, int $perPage): array
    {
        $applyFilters = function ($query) use ($filters) {
            if (!empty($filters['role'])) {
                $query->where('r.slug', $filters['role']);
            }
            if (!empty($filters['status'])) {
                $query->where('u.status', $filters['status']);
            }
            if (!empty($filters['search'])) {
                $q = '%' . $filters['search'] . '%';
                $query->where(function ($w) use ($q) {
                    $w->where('u.full_name', 'like', $q)
                        ->orWhere('u.email', 'like', $q)
                        ->orWhere('u.uuid', 'like', $q);
                });
            }
        };

        $countQuery = $this->rolesWithOrgQuery();
        $applyFilters($countQuery);
        $total = $countQuery->count('u.id');

        $perPage = max(1, $perPage);
        $offset = max(0, $page - 1) * $perPage;

        $rowsQuery = $this->rolesWithOrgQuery()
            ->select(
                'u.id', 'u.uuid', 'u.full_name', 'u.email', 'u.status', 'u.created_at',
                'r.slug as role',
                DB::raw('COALESCE(uni.official_name_en, s.faculty) as org_en'),
                DB::raw('COALESCE(uni.official_name_ar, s.faculty) as org_ar')
            );
        $applyFilters($rowsQuery);

        $rows = $rowsQuery->orderByDesc('u.created_at')->limit($perPage)->offset($offset)->get()
            ->map(fn ($row) => (array) $row)->all();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * منقولة من UserRepository::findWithRoleByUuid() القديمة بالظبط.
     *     */
    public function findWithRoleByUuid(string $uuid): ?array
    {
        $row = DB::table('users as u')
            ->leftJoin(DB::raw('(SELECT ur1.user_id, ur1.role_id FROM user_roles ur1
                    WHERE ur1.assigned_at = (SELECT MIN(ur2.assigned_at) FROM user_roles ur2 WHERE ur2.user_id = ur1.user_id)) as ur'), 'ur.user_id', '=', 'u.id')
            ->leftJoin('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('u.uuid', $uuid)
            ->whereNull('u.deleted_at')
            ->select('u.id', 'u.uuid', 'u.full_name', 'u.email', 'u.phone', 'u.status', 'u.created_at',
                'u.last_login_at', 'u.last_login_ip', 'r.slug as role')
            ->first();

        return $row ? (array) $row : null;
    }

    /** منقولة من UserRepository::updateStatus() القديمة بالظبط. */
    public function updateStatus($userId, string $status): bool
    {
        $user = User::find($userId);
        if (!$user) {
            return false;
        }
        $user->fill(['status' => $status]);
        return $user->save();
    }

    /**
     * منقولة من UserRepository::delete() القديمة بالظبط — soft delete +
     * status='banned'. القديمة كانت بتحدّث deleted_at يدوي؛ هنا برضه بنحط
     * status='banned' زيها بالظبط لكن عبر SoftDeletes::delete() الرسمية
     * بتاعة الموديل (Laravel User model عليه `use SoftDeletes`) عشان أي
     * scope تاني في المشروع بيعتمد على deleted_at ماشي عادي.
     */
    public function delete($userId): bool
    {
        $user = User::find($userId);
        if (!$user || $user->trashed()) {
            return false;
        }
        $user->fill(['status' => 'banned']);
        $user->save();
        return $user->delete();
    }

    // -- بند 25 batch 9 (Admin > Users — CRUD + suspend/activate/role/block-ip) --

    /** منقولة من UserRepository::countsByStatus() القديمة بالظبط — بطاقات إحصائيات صفحة Users. */
    public function countsByStatus(): array
    {
        $row = DB::table('users')
            ->whereNull('deleted_at')
            ->selectRaw("COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended")
            ->first();

        return [
            'total'     => (int) ($row->total ?? 0),
            'active'    => (int) ($row->active ?? 0),
            'suspended' => (int) ($row->suspended ?? 0),
        ];
    }

    /**
     * منقولة من UserRepository::allRoleSlugs() القديمة بالظبط — كل
     * الأدوار اللي المستخدم ده عنده، الأساسي (assigned_at الأقدم) الأول.
     * @return string[]
     */
    public function allRoleSlugs($userId): array
    {
        return DB::table('roles as r')
            ->join('user_roles as ur', 'ur.role_id', '=', 'r.id')
            ->where('ur.user_id', $userId)
            ->orderBy('ur.assigned_at', 'asc')
            ->pluck('r.slug')
            ->all();
    }

    /** منقولة من UserRepository::additionalRoleSlugs() القديمة بالظبط — أي دور بعد الأساسي. */
    public function additionalRoleSlugs($userId): array
    {
        $all = $this->allRoleSlugs($userId);
        return array_slice($all, 1);
    }

    /**
     * منقولة من UserRepository::grantAdditionalRole() القديمة بالظبط —
     * تضيف دور فوق الأدوار الحالية من غير ما تلمس الدور الأساسي. بترجع
     * false بس لو الـ slug مش موجود؛ لو المستخدم عنده الدور بالفعل
     * بتعمل no-op بفضل PRIMARY KEY (user_id, role_id).
     */
    public function grantAdditionalRole($userId, string $roleSlug): bool
    {
        $roleId = Role::where('slug', $roleSlug)->value('id');
        if (!$roleId) {
            return false;
        }
        $inserted = DB::affectingStatement(
            'INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)',
            [$userId, $roleId]
        );
        if ($inserted > 0) {
            app(\App\Services\SecurityAlertService::class)->privilegeGranted((int) $userId, $roleSlug, request()?->attributes->get('uip_user_id'));
        }
        return true;
    }

    /**
     * منقولة من UserRepository::revokeAdditionalRole() القديمة بالظبط —
     * بترفض تشيل الدور الأساسي (changeRole() هي الطريقة الصح لده) أو
     * آخر دور متبقي، عشان مستخدم ميفضلش من غير أي دور.
     */
    public function revokeAdditionalRole($userId, string $roleSlug): bool
    {
        $all = $this->allRoleSlugs($userId);
        if (count($all) <= 1 || ($all[0] ?? null) === $roleSlug) {
            return false;
        }
        $roleId = Role::where('slug', $roleSlug)->value('id');
        if (!$roleId) {
            return false;
        }
        DB::table('user_roles')->where('user_id', $userId)->where('role_id', $roleId)->delete();
        return true;
    }

    /** منقولة من UserRepository::updateProfile() القديمة بالظبط — full_name/email/phone/status مرة واحدة. */
    public function updateProfile($userId, array $data): bool
    {
        $user = User::find($userId);
        if (!$user) {
            return false;
        }
        $user->fill(array_filter([
            'full_name' => $data['full_name'] ?? null,
            'email'     => $data['email'] ?? null,
            'phone'     => array_key_exists('phone', $data) ? $data['phone'] : null,
            'status'    => $data['status'] ?? null,
        ], fn ($v) => $v !== null));
        return $user->save();
    }

    /**
     * منقولة من UserRepository::changeRole() القديمة بالظبط — بتستبدل
     * الدور الأساسي (single-role UX زي القديمة)، بتمسح كل صفوف
     * user_roles القديمة وتحط صف واحد جديد. بترجع false لو الـ slug
     * مش موجود.
     */
    public function changeRole($userId, string $roleSlug): bool
    {
        $roleId = Role::where('slug', $roleSlug)->value('id');
        if (!$roleId) {
            return false;
        }
        DB::table('user_roles')->where('user_id', $userId)->delete();
        DB::table('user_roles')->insert(['user_id' => $userId, 'role_id' => $roleId]);
        app(\App\Services\SecurityAlertService::class)->privilegeGranted((int) $userId, $roleSlug, request()?->attributes->get('uip_user_id'));
        return true;
    }
}
