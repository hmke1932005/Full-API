<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Repositories\BlockedIpRepository;
use App\Repositories\UserRepository;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/AdminUsersApiController.php القديمة —
 * بند 25 batch 9 (Admin > Users، CRUD + suspend/activate/role/block-ip).
 * سطح /api/v1/admin/users/* واحد لدليل المستخدمين الكامل (كل الأدوار
 * الستة)، بيستخدم نفس UserRepository/AuditLogService/BlockedIpRepository
 * بالظبط زي Admin\AdminUserManagementController القديمة — index/store/
 * show/update/destroy زائد الأكشنز اللي Admin بيمتلكها فعليًا: suspend/
 * activate، تغيير الدور الأساسي، منح/سحب دور إضافي، وحظر آخر IP معروف.
 * كل تعديل بيتسجل في audit log عبر AuditLogService::record()، نفس نداءات
 * الكنترولر القديمة بالظبط.
 *
 * RBAC: middleware uip.auth + uip.admin بيقفلوا المجموعة كلها (routes/api.php)،
 * زي ما القديمة كانت بتعمل بـ Session::hasRole('admin') على كل ميثود.
 */
class AdminUsersApiController extends Controller
{
    private const PER_PAGE_DEFAULT = 20;
    private const PER_PAGE_MAX = 100;

    public function __construct(
        private UserRepository $users,
        private AuditLogService $auditLog,
        private BlockedIpRepository $blockedIps
    ) {
    }

    private function requireAdmin(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can access the user directory.', null, 403);
        }
        return null;
    }

    /** GET /api/v1/admin/users — spec §7 pagination/search/filter. */
    public function index(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $filters = array_filter([
            'role'   => $request->input('role') ?: null,
            'status' => $request->input('status') ?: null,
            'search' => $request->input('q') ?: null,
        ]);
        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min(self::PER_PAGE_MAX, (int) $request->input('per_page', self::PER_PAGE_DEFAULT)));

        $result = $this->users->paginateWithRoles($filters, $page, $perPage);

        return $this->apiSuccess($result['rows'], 'Users retrieved successfully.', 200, [
            'page' => $page, 'perPage' => $perPage, 'total' => $result['total'],
            'counts_by_status' => $this->users->countsByStatus(),
        ]);
    }

    /** GET /api/v1/admin/users/{id} — {id} is the user's uuid. */
    public function show(Request $request, $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $target = $this->users->findWithRoleByUuid((string) $id);
        if (!$target) {
            return $this->apiError('User not found.', null, 404);
        }

        $target['additional_roles'] = $this->users->additionalRoleSlugs($target['id']);

        return $this->apiSuccess($target, 'User retrieved successfully.');
    }

    /**
     * POST /api/v1/admin/users — generic "create account" used across
     * Users/Universities/Team pages on the web, preset by role.
     */
    public function store(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $data = $request->validate([
            'email'     => 'required|email',
            'password'  => 'required|string',
            'role'      => 'required',
        ]);

        try {
            app(\App\Services\PasswordPolicyService::class)->assertValid($data['password']);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $names = \App\Support\BilingualName::fromRequest($request);
        if (!$names['ok']) {
            return $this->apiError('Please provide the name in both Arabic and English.', $names['errors'], 422);
        }

        if ($this->users->emailExists($data['email'])) {
            return $this->apiError('An account with this email already exists.', null, 422);
        }

        if (!Role::where('slug', $data['role'])->exists()) {
            return $this->apiError('That role does not exist.', null, 422);
        }

        $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT);

        $user = $this->users->createWithRole([
            'full_name'          => $names['full_name'],
            'name_ar'            => $names['name_ar'],
            'name_en'            => $names['name_en'],
            'email'              => $data['email'],
            'phone'              => $request->input('phone') ?: null,
            'password_hash'      => $passwordHash,
            'preferred_language' => 'ar',
            'status'             => 'active',
            'email_verified_at'  => now(),
        ], $data['role']);

        app(\App\Services\PasswordPolicyService::class)->recordPasswordChange($user->id, $passwordHash);

        $this->auditLog->record($request->attributes->get('uip_user_id'), 'user.create', 'User', $user->id, null, [
            'email' => $user->email, 'role' => $data['role'],
        ]);

        return $this->apiSuccess($user->toArray(), 'Account created successfully.', 201);
    }

    /** PATCH /api/v1/admin/users/{id} — profile fields + optional role change. */
    public function update(Request $request, $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $target = $this->users->findWithRoleByUuid((string) $id);
        if (!$target) {
            return $this->apiError('User not found.', null, 404);
        }

        $data = $request->validate([
            'email'     => 'required|email',
        ]);

        $names = \App\Support\BilingualName::fromRequest($request);
        if (!$names['ok']) {
            return $this->apiError('Please provide the name in both Arabic and English.', $names['errors'], 422);
        }
        $data += \App\Support\BilingualName::columns($names);

        $ok = $this->users->updateProfile($target['id'], [
            'full_name' => $names['full_name'],
            'name_ar'   => $names['name_ar'],
            'name_en'   => $names['name_en'],
            'email'     => $data['email'],
            'phone'     => $request->input('phone') ?: null,
            'status'    => $request->input('status') ?: $target['status'],
        ]);

        $newRole = (string) $request->input('role', $target['role']);
        if ($newRole !== $target['role']) {
            $this->users->changeRole($target['id'], $newRole);
        }

        if ($ok) {
            $this->auditLog->record($request->attributes->get('uip_user_id'), 'user.update', 'User', $target['id'], [
                'full_name' => $target['full_name'], 'email' => $target['email'],
            ], $data);
        }

        return $ok
            ? $this->apiSuccess(null, 'User updated successfully.')
            : $this->apiError('Could not update this user.', null, 422);
    }

    /** DELETE /api/v1/admin/users/{id} — soft delete; an admin cannot delete their own account. */
    public function destroy(Request $request, $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $target = $this->users->findWithRoleByUuid((string) $id);
        if (!$target) {
            return $this->apiError('User not found.', null, 404);
        }

        if ((int) $target['id'] === (int) $request->attributes->get('uip_user_id')) {
            return $this->apiError('You cannot delete your own account.', null, 422);
        }

        $ok = $this->users->delete($target['id']);

        if ($ok) {
            $this->auditLog->record($request->attributes->get('uip_user_id'), 'user.delete', 'User', $target['id'], ['email' => $target['email']], null);
        }

        return $ok
            ? $this->apiSuccess(null, 'User deleted successfully.')
            : $this->apiError('Could not delete this user.', null, 422);
    }

    /** POST /api/v1/admin/users/{id}/suspend */
    public function suspend(Request $request, $id)
    {
        return $this->setStatus($request, $id, 'suspended', 'User suspended.', 'user.suspend');
    }

    /** POST /api/v1/admin/users/{id}/activate */
    public function activate(Request $request, $id)
    {
        return $this->setStatus($request, $id, 'active', 'User reactivated.', 'user.activate');
    }

    /** PATCH /api/v1/admin/users/{id}/role — replaces the user's primary role entirely. */
    public function changeRole(Request $request, $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $target = $this->users->findWithRoleByUuid((string) $id);
        if (!$target) {
            return $this->apiError('User not found.', null, 404);
        }

        $newRole = (string) $request->input('role', '');
        $ok = $this->users->changeRole($target['id'], $newRole);

        if ($ok) {
            $this->auditLog->record($request->attributes->get('uip_user_id'), 'user.role_change', 'User', $target['id'], ['role' => $target['role']], ['role' => $newRole]);
        }

        return $ok
            ? $this->apiSuccess(null, 'Role updated successfully.')
            : $this->apiError('That role does not exist.', null, 422);
    }

    /** POST /api/v1/admin/users/{id}/roles — grants an ADDITIONAL role on top of the user's existing access. */
    public function assignRole(Request $request, $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $target = $this->users->findWithRoleByUuid((string) $id);
        if (!$target) {
            return $this->apiError('User not found.', null, 404);
        }

        $roleSlug = (string) $request->input('role', '');
        $ok = $this->users->grantAdditionalRole($target['id'], $roleSlug);

        if ($ok) {
            $this->auditLog->record($request->attributes->get('uip_user_id'), 'user.additional_role_grant', 'User', $target['id'], null, ['role_granted' => $roleSlug]);
        }

        return $ok
            ? $this->apiSuccess(null, 'Additional access granted successfully.', 201)
            : $this->apiError('That role does not exist.', null, 422);
    }

    /** DELETE /api/v1/admin/users/{id}/roles/{role} — revokes an additional role (never the user's primary/only one). */
    public function revokeRole(Request $request, $id, $role)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $target = $this->users->findWithRoleByUuid((string) $id);
        if (!$target) {
            return $this->apiError('User not found.', null, 404);
        }

        $roleSlug = (string) $role;
        $ok = $this->users->revokeAdditionalRole($target['id'], $roleSlug);

        if ($ok) {
            $this->auditLog->record($request->attributes->get('uip_user_id'), 'user.additional_role_revoke', 'User', $target['id'], ['role_revoked' => $roleSlug], null);
        }

        return $ok
            ? $this->apiSuccess(null, 'Access revoked successfully.')
            : $this->apiError('That access can\'t be revoked (it\'s their primary role, or their only remaining one).', null, 422);
    }

    /** POST /api/v1/admin/users/{id}/block-ip — blocks the user's last-known login IP and suspends the account as a precaution. */
    public function blockIp(Request $request, $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $target = $this->users->findWithRoleByUuid((string) $id);
        if (!$target || empty($target['last_login_ip'])) {
            return $this->apiError('No known IP address for this user.', null, 422);
        }

        $ip = $target['last_login_ip'];
        $ok = $this->blockedIps->block($ip, 'Blocked from user #' . $target['id'] . ' (' . $target['email'] . ')', $request->attributes->get('uip_user_id'));
        $this->users->updateStatus($target['id'], 'suspended');

        if ($ok) {
            $this->auditLog->record($request->attributes->get('uip_user_id'), 'ip.block', 'User', $target['id'], null, ['ip' => $ip]);
        }

        return $ok
            ? $this->apiSuccess(['ip' => $ip], 'IP blocked successfully.', 201)
            : $this->apiError('That IP is already blocked.', null, 422);
    }

    // -- helpers --------------------------------------------------------------

    private function setStatus(Request $request, $id, string $status, string $successMessage, string $action)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $target = $this->users->findWithRoleByUuid((string) $id);
        if (!$target) {
            return $this->apiError('User not found.', null, 404);
        }

        $ok = $this->users->updateStatus($target['id'], $status);

        if ($ok) {
            $this->auditLog->record($request->attributes->get('uip_user_id'), $action, 'User', $target['id'], ['status' => $target['status']], ['status' => $status]);
        }

        return $ok
            ? $this->apiSuccess(null, $successMessage)
            : $this->apiError('Could not update this user.', null, 422);
    }
}
