<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\SupervisorAssignmentRepository;
use App\Repositories\SupervisorRepository;
use App\Repositories\UserRepository;
use App\Services\AuditLogService;
use App\Services\NotificationPreferencesService;
use App\Services\PasswordPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * منقولة من app/Controllers/Api/SupervisorSettingsApiController.php القديمة
 * — بند 9 (Supervisors)، جزء 2. سطح إعدادات مقيّد لحساب لوجين المشرف نفسه
 * (api/v1/supervisor/settings/*)، بيطابق
 * App\Controllers\Supervisor\SupervisorSettingsController (web) القديمة
 * بالظبط: بروفايل روستر للقراءة بس (department/title/permissions/نطاقات
 * مُسندة — الجامعة الداعية بس اللي تعدّلهم عبر
 * /api/v1/supervisors/{id})، تفضيلات إشعارات مستوى-فئة، وتغيير باسورد
 * حقيقي. مفيش avatar/2FA/theme toggle هنا لأن القديمة ماكانتش عاملاهم
 * لحساب المشرف (على عكس باقي بورتالات الـ Settings).
 *
 * فرق شكلي فقط عن القديمة (نفس نمط بند 6/8):
 *  - Session::userId()/hasRole() -> $request->attributes->get('uip_user_id')/'uip_role'.
 *  - NotificationService -> NotificationPreferencesService (نفس تبديل
 *    بند 6/8، الميثودز الفعلية زي ما هي).
 *  - $this->validate([...]) -> Validator::make() صريح + updatePassword()
 *    بتاخد UserRepository::updatePassword() زيادة عن
 *    PasswordPolicyService::recordPasswordChange() (نفس نمط
 *    باقي كنترولرز الـ Settings::updatePassword()).
 *  - \RuntimeException -> \InvalidArgumentException (شوف
 *    PasswordPolicyService الحالية).
 *
 * RBAC: uip.auth بتغطي الجروب (routes/api.php)؛ role='supervisor' بتتفحص
 * كمان جوه كل ميثود عبر uip_role.
 */
class SupervisorSettingsApiController extends Controller
{
    public function __construct(
        private SupervisorRepository $supervisors,
        private SupervisorAssignmentRepository $assignments,
        private UserRepository $users,
        private PasswordPolicyService $passwordPolicy,
        private NotificationPreferencesService $notifications,
        private AuditLogService $auditLog
    ) {
    }

    /** GET /api/v1/supervisor/settings — بروفايل روستر للقراءة بس + نطاقاته + تفضيلات الإشعارات. Role مشرف بس. */
    public function index(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'supervisor') {
            return $this->apiError('Only supervisor accounts can view these settings.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $supervisor = $this->supervisors->findActiveByUserId($userId);

        return $this->apiSuccess([
            'supervisor'              => $supervisor,
            'scopes'                  => $supervisor ? $this->assignments->forSupervisorWithLabels($supervisor->id) : [],
            'notification_categories' => $this->notifications->categoryLabels(),
            'muted_categories'        => $this->notifications->mutedCategoriesFor($userId),
            'digest_frequency'        => $this->notifications->digestFrequencyFor($userId),
            'quiet_hours'             => $this->notifications->quietHoursFor($userId),
        ], 'Settings retrieved successfully.');
    }

    /** PATCH /api/v1/supervisor/settings/notifications — Role مشرف بس. */
    public function updateNotificationPreferences(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'supervisor') {
            return $this->apiError('Only supervisor accounts can update notification preferences.', null, 403);
        }

        $userId = (int) $request->attributes->get('uip_user_id');

        $before = [
            'muted_categories' => $this->notifications->mutedCategoriesFor($userId),
            'digest_frequency' => $this->notifications->digestFrequencyFor($userId),
            'quiet_hours'      => $this->notifications->quietHoursFor($userId),
        ];

        $enabledCategories = (array) $request->input('enabled_categories', []);
        $allCategories = array_keys($this->notifications->categoryLabels());
        $muted = array_diff($allCategories, array_map('strval', $enabledCategories));
        $this->notifications->setMutedCategories($userId, $muted);

        $digest = (string) $request->input('digest_frequency', 'immediate');
        $this->notifications->setDigestFrequency($userId, $digest);

        $quietStart = trim((string) $request->input('quiet_hours_start', ''));
        $quietEnd = trim((string) $request->input('quiet_hours_end', ''));
        $this->notifications->setQuietHours($userId, $quietStart ?: null, $quietEnd ?: null);

        $after = [
            'muted_categories' => array_values($muted),
            'digest_frequency' => $digest,
            'quiet_hours'      => ['start' => $quietStart ?: null, 'end' => $quietEnd ?: null],
        ];
        $this->auditLog->record($userId, 'supervisor.notification_preferences_update', 'Setting', null, $before, $after);

        return $this->apiSuccess($after, 'Notification preferences saved successfully.');
    }

    /** PATCH /api/v1/supervisor/settings/password — Role مشرف بس. */
    public function updatePassword(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'supervisor') {
            return $this->apiError('Only supervisor accounts can update this password.', null, 403);
        }

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|confirmed',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }
        $data = $validator->validated();

        $user = User::find((int) $request->attributes->get('uip_user_id'));
        if (!$user || !password_verify($data['current_password'], (string) $user->password_hash)) {
            return $this->apiError('Current password is incorrect.', null, 422);
        }

        try {
            $this->passwordPolicy->assertValid($data['new_password']);
            $this->passwordPolicy->assertNotReused($user->id, $data['new_password']);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        }

        $newHash = password_hash($data['new_password'], PASSWORD_BCRYPT);
        $this->users->updatePassword($user->id, $newHash);
        $this->passwordPolicy->recordPasswordChange($user->id, $newHash);

        return $this->apiSuccess(null, 'Password updated successfully.');
    }
}
