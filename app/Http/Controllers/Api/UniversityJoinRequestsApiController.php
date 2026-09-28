<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StudentJoinRequestService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/UniversityJoinRequestsApiController.php
 * القديمة — سطح REST واحد بيلف StudentJoinRequestService بالظبط زي
 * University\UniversityJoinRequestController (الويب) — نفس صفوف pending/
 * سجل كامل، نفس انتقالات approve()/reject() (المكان الوحيد اللي فعلًا
 * بيكتب students.university_id).
 *
 * RBAC: uip.auth بتغطي المجموعة كله؛ كل أكشن مقيّد بجامعة الكولر نفسه عبر
 * StudentJoinRequestService::universityIdForUser()، أبدًا مش id جاي من
 * العميل.
 */
class UniversityJoinRequestsApiController extends Controller
{
    public function __construct(private StudentJoinRequestService $joinRequests)
    {
    }

    /** GET /api/v1/university/join-requests — طابور pending + سجل كامل + عدادات. */
    public function index(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $universityId = $this->joinRequests->universityIdForUser($userId);
        if (!$universityId) {
            return $this->apiError('Only university accounts have a join-request queue.', null, 403);
        }

        $locale = (string) $request->input('locale', 'en');
        $pending = $this->joinRequests->pendingQueue($universityId, $locale);
        $full = $this->joinRequests->fullQueue($universityId, $locale);

        return $this->apiSuccess([
            'pending' => $pending,
            'all'     => $full,
            'counts'  => [
                'pending' => count($pending),
                'total'   => count($full),
            ],
        ], 'Join requests retrieved successfully.');
    }

    /** POST /api/v1/university/join-requests/{id}/approve */
    public function approve(Request $request, int $id)
    {
        return $this->decide($request, $id, 'approve', 'Student approved and linked to your university.', 'Request could not be approved.');
    }

    /** POST /api/v1/university/join-requests/{id}/reject */
    public function reject(Request $request, int $id)
    {
        return $this->decide($request, $id, 'reject', 'Request declined.', 'Request could not be declined.');
    }

    private function decide(Request $request, int $id, string $action, string $successMessage, string $errorMessage)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $universityId = $this->joinRequests->universityIdForUser($userId);
        if (!$universityId) {
            return $this->apiError('Only university accounts can decide on join requests.', null, 403);
        }

        $ok = $this->joinRequests->$action($id, $universityId, $userId);
        if (!$ok) {
            return $this->apiError($errorMessage, null, 409);
        }

        return $this->apiSuccess(null, $successMessage);
    }
}
