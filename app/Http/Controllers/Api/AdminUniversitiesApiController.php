<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\UniversityRepository;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/AdminUniversitiesApiController.php القديمة —
 * بند 25 batch 2. سطح /api/v1/admin/universities/* JSON واحد، بيعيد
 * استخدام UniversityRepository بالظبط زي ما
 * Admin\AdminUniversityVerificationController القديمة كانت بتعمل للـ view
 * (بحث/فلتر حالة/ترقيم، verify/reject، إعدادات إعادة الاعتماد، حذف نهائي).
 * مفيش حاجة مختلَقة هنا.
 *
 * فرق شكلي فقط عن القديمة (نفس نمط باقي البنود):
 *  - Session::hasRole('admin')/userId() -> $request->attributes->get('uip_role'|'uip_user_id').
 *  - $this->input()/$this->param() -> $request->input()/route parameter.
 */
class AdminUniversitiesApiController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private UniversityRepository $universities,
        private AuditLogService $auditLog
    ) {
    }

    private function requireAdmin(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can manage universities.', null, 403);
        }
        return null;
    }

    /** GET /api/v1/admin/universities */
    public function index(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $q = trim((string) $request->input('q', ''));
        $status = (string) $request->input('status', '');
        $page = max(1, (int) $request->input('page', 1));

        $result = $this->universities->paginateWithStats($q, $status, $page, self::PER_PAGE);

        return $this->apiSuccess($result['rows'], 'Universities retrieved successfully.', 200, [
            'page' => $page, 'perPage' => self::PER_PAGE, 'total' => $result['total'],
            'counts' => $this->universities->countStats(),
        ]);
    }

    /** POST /api/v1/admin/universities/{id}/verify */
    public function verify(Request $request, string $id)
    {
        return $this->decide($request, $id, 'verified', 'University verified.', 'university.verify');
    }

    /** POST /api/v1/admin/universities/{id}/reject */
    public function reject(Request $request, string $id)
    {
        return $this->decide($request, $id, 'rejected', 'University application rejected.', 'university.reject');
    }

    /** PATCH /api/v1/admin/universities/{id}/reverification */
    public function updateReverification(Request $request, string $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $periodDays = (int) $request->input('verification_period_days', 0);
        $autoReverify = (bool) $request->input('auto_reverify_enabled', false);

        $ok = $this->universities->updateReverificationSettings((int) $id, $periodDays > 0 ? $periodDays : null, $autoReverify);

        if ($ok) {
            $this->auditLog->record($request->attributes->get('uip_user_id'), 'university.reverification_settings_update', 'University', (int) $id, null, [
                'verification_period_days' => $periodDays ?: null,
                'auto_reverify_enabled'    => $autoReverify,
            ]);
        }

        return $ok
            ? $this->apiSuccess(null, 'Re-verification settings updated.')
            : $this->apiError('University not found.', null, 404);
    }

    /** DELETE /api/v1/admin/universities/{id} — hard delete + bans the linked account. */
    public function destroy(Request $request, string $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $ok = $this->universities->delete((int) $id);

        if ($ok) {
            $this->auditLog->record($request->attributes->get('uip_user_id'), 'university.delete', 'University', (int) $id);
        }

        return $ok
            ? $this->apiSuccess(null, 'University removed.')
            : $this->apiError('University not found.', null, 404);
    }

    private function decide(Request $request, string $id, string $status, string $successMessage, string $action)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $adminId = $request->attributes->get('uip_user_id');
        $ok = $this->universities->decide((int) $id, $status, $adminId);

        if ($ok) {
            $this->auditLog->record($adminId, $action, 'University', (int) $id,
                ['verification_status' => 'pending'], ['verification_status' => $status]);
        }

        return $ok
            ? $this->apiSuccess(null, $successMessage)
            : $this->apiError('That application was already decided.', null, 422);
    }
}
