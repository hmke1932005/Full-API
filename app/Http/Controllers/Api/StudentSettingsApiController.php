<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\SettingRepository;
use App\Repositories\StudentRepository;
use App\Repositories\UserRepository;
use App\Services\AuditLogService;
use App\Services\NotificationPreferencesService;
use App\Services\PasswordPolicyService;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * سطح إعدادات /api/v1/student/settings/* لحساب لوجين الطالب، بنفس نمط
 * DataAnalysisSettingsApiController/FacultySettingsApiController
 * (بروفايل للقراءة بس — الاسم/الإيميل بيتغيروا من إدارة الجامعة مش هنا،
 * زي ما StudentSettings.jsx بتوثقه — تفضيلات ثيم/لغة/إشعارات email،
 * كارت إشعارات category-level عبر NotificationPreferencesService
 * المشتركة، خصوصية مراسلة (read receipts) عبر نفس مفتاح الـ setting اللي
 * MessagingService بيقرا منه، تغيير باسورد، وTOTP 2FA كامل).
 *
 * RBAC: uip.auth بتغطي الجروب (routes/api.php)؛ role='student' بتتفحص
 * جوه كل ميثود.
 */
class StudentSettingsApiController extends Controller
{
    public function __construct(
        private StudentRepository $students,
        private SettingRepository $settings,
        private UserRepository $users,
        private PasswordPolicyService $passwordPolicy,
        private AuditLogService $auditLog,
        private TwoFactorService $twoFactor,
        private NotificationPreferencesService $notifications
    ) {
    }

    private function requireStudent(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'student') {
            return $this->apiError('Only student accounts can access these settings.', null, 403);
        }
        return null;
    }

    /** GET /api/v1/student/settings */
    public function index(Request $request)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $student = $this->students->findByUserId($userId);
        $profile = $student ? $this->students->withProfileDetails($student->id) : null;

        return $this->apiSuccess([
            'profile'                  => $profile,
            'email_notifications'      => $this->settings->get('email_notifications', 'user', $userId, '1') === '1',
            'read_receipts_enabled'    => $this->settings->get('messaging_read_receipts_enabled', 'user', $userId, '1') === '1',
            'two_factor'               => $this->twoFactor->status($userId),
            'notification_categories'  => $this->notifications->categoryLabels(),
            'muted_categories'         => $this->notifications->mutedCategoriesFor($userId),
            'digest_frequency'         => $this->notifications->digestFrequencyFor($userId),
            'quiet_hours'              => $this->notifications->quietHoursFor($userId),
        ], 'Settings retrieved successfully.');
    }

    /** PATCH /api/v1/student/settings/preferences — language + theme + email notifications. */
    public function updatePreferences(Request $request)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $user = User::find($userId);
        if (!$user) {
            return $this->apiError('User not found.', null, 404);
        }

        $language = (string) $request->input('preferred_language', $user->preferred_language ?? 'ar');
        $theme = (string) $request->input('theme_preference', $user->theme_preference ?? 'light');
        $emailNotifications = $request->input('email_notifications') ? '1' : '0';

        $fill = [];
        if (in_array($language, ['ar', 'en'], true)) {
            $fill['preferred_language'] = $language;
        }
        if (in_array($theme, ['light', 'dark'], true)) {
            $fill['theme_preference'] = $theme;
        }
        if ($fill) {
            $user->fill($fill);
            $user->save();
        }

        $this->settings->set('email_notifications', $emailNotifications, 'user', $userId);

        return $this->apiSuccess([
            'preferred_language'  => $user->preferred_language,
            'theme_preference'    => $user->theme_preference,
            'email_notifications' => $emailNotifications === '1',
        ], 'Preferences saved successfully.');
    }

    /** PATCH /api/v1/student/settings/notifications */
    public function updateNotificationPreferences(Request $request)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
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
        $this->auditLog->record($userId, 'student.notification_preferences_update', 'Setting', null, $before, $after);

        return $this->apiSuccess($after, 'Notification preferences saved successfully.');
    }

    /** PATCH /api/v1/student/settings/messaging-privacy — read receipts toggle. */
    public function updateMessagingPrivacy(Request $request)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $enabled = $request->input('read_receipts_enabled') ? '1' : '0';
        $this->settings->set('messaging_read_receipts_enabled', $enabled, 'user', $userId);

        $this->auditLog->record($userId, 'student.messaging_privacy_update', 'Setting', null, null, ['read_receipts_enabled' => $enabled === '1']);

        return $this->apiSuccess(['read_receipts_enabled' => $enabled === '1'], 'Messaging privacy saved successfully.');
    }

    /** PATCH /api/v1/student/settings/password */
    public function updatePassword(Request $request)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|confirmed',
        ]);
        if ($validator->fails()) {
            return $this->apiError($validator->errors()->first(), $validator->errors()->toArray(), 422);
        }
        $data = $validator->validated();

        $userId = (int) $request->attributes->get('uip_user_id');
        $user = User::find($userId);
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
        $this->auditLog->record($userId, 'student.password_change', 'User', $user->id);

        return $this->apiSuccess(null, 'Password changed successfully.');
    }

    /** POST /api/v1/student/settings/2fa/setup */
    public function twoFactorSetup(Request $request)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $status = $this->twoFactor->status($userId);
        if ($status['enabled']) {
            return $this->apiError('Two-factor authentication is already enabled.', null, 422);
        }

        $user = User::find($userId);
        $setup = $this->twoFactor->generateSetup($userId, (string) $user->email);

        return $this->apiSuccess($setup, 'Two-factor setup generated successfully.');
    }

    /** POST /api/v1/student/settings/2fa/confirm */
    public function twoFactorConfirm(Request $request)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $setupToken = (string) $request->input('setup_token');
        $code = trim((string) $request->input('code'));
        $recoveryCodes = $this->twoFactor->confirmSetup($userId, $setupToken, $code);

        if ($recoveryCodes === null) {
            return $this->apiError('That code did not match, or the setup request expired. Please try again.', null, 422);
        }

        $this->auditLog->record($userId, 'student.two_factor_enabled', 'User', $userId);

        return $this->apiSuccess(['recovery_codes' => $recoveryCodes], 'Two-factor authentication enabled successfully.', 201);
    }

    /** POST /api/v1/student/settings/2fa/disable */
    public function twoFactorDisable(Request $request)
    {
        if ($err = $this->requireStudent($request)) {
            return $err;
        }

        $userId = (int) $request->attributes->get('uip_user_id');
        $ok = $this->twoFactor->disable($userId, (string) $request->input('current_password'));

        if ($ok) {
            $this->auditLog->record($userId, 'student.two_factor_disabled', 'User', $userId);
        }

        return $ok
            ? $this->apiSuccess(null, 'Two-factor authentication disabled successfully.')
            : $this->apiError('Incorrect password — two-factor authentication was not disabled.', null, 422);
    }
}
