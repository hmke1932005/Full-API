<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Services/PermissionService.php القديمة (Core\Database ->
 * DB facade). الفرق الوحيد عن القديمة: مفيش Core\Session ثابت هنا، فبدل
 * ما currentUserCan() تقرا Session::userId()/Session::userRole() لوحدها،
 * الكولر (uip.auth middleware بيحطهم في $request->attributes) بيمررهم
 * صراحة — نفس فكرة AuditLogService::record($userId, ...) في بند 3/4.
 *
 * "أدمن دايمًا true" — نفس قاعدة RoleMiddleware/MaintenanceMiddleware
 * القديمة، عشان تعديل صلاحية أبدًا ميقفلش حساب الأدمن نفسه بالغلط.
 */
class PermissionService
{
    /** @return string[] permission slugs, e.g. ['project.approve', 'analytics.view', ...] */
    public function permissionsForUser(int $userId): array
    {
        return DB::table('user_roles as ur')
            ->join('role_permission as rp', 'rp.role_id', '=', 'ur.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('ur.user_id', $userId)
            ->distinct()
            ->pluck('p.slug')
            ->all();
    }

    public function userHasPermission(int $userId, string $permissionSlug): bool
    {
        return DB::table('user_roles as ur')
            ->join('role_permission as rp', 'rp.role_id', '=', 'ur.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('ur.user_id', $userId)
            ->where('p.slug', $permissionSlug)
            ->exists();
    }

    /**
     * الشيك اللي أغلب الكولرز فعلًا محتاجاه: المستخدم الحالي (uip_user_id/
     * uip_role جايين من $request->attributes، شوف docblock الكلاس) مسموح
     * له يعمل كذا؟ role='admin' بترجع true على طول.
     */
    public function currentUserCan(?int $userId, ?string $role, string $permissionSlug): bool
    {
        if (!$userId) {
            return false;
        }
        if ($role === 'admin') {
            return true;
        }
        return $this->userHasPermission($userId, $permissionSlug);
    }
}
