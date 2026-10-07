<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\SettingRepository;
use App\Services\AccountLockoutService;
use App\Services\RoleService;
use App\Services\TrustedDeviceService;
use App\Services\UipJwtService;
use App\Support\AuthCookies;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * يطابق LoginController::submit() + AuthService::attemptLogin() القديمين
 * — الجزء الأساسي + Account Lockout + Two-Factor بالكامل دلوقتي.
 *
 * ⚠️ لسه متعمّد مش موجود (بند منفصل — Security Portal، بند 25 في خطة
 * الهجرة): IP / Country / Device restriction policies، Concurrent Session
 * Limits، MFA mandatory-policy enforcement. دول واجهة إدارة كاملة
 * (allow/deny lists، GeoIP) هتتنقل مع باقي الـ Security Portal مرة واحدة.
 *
 * Maintenance mode enforcement (AdminSettingsApiController::toggleMaintenance()):
 * كان بيتخزن في settings بس من غير أي إنفاذ فعلي — أي حد (admin أو لأ)
 * كان لسه قادر يعمل submit() عادي وهو ON. هنا بنفحصه بعد ما نعرف الـ role
 * (عشان admin يقدر يدخل دايمًا يطفيه) وقبل أي إصدار توكن/تحدي 2FA.
 */
class LoginController extends Controller
{
    /**
     * أدوار الستاف: بتدخل من بوابة الدخول المخفية بس (portal=staff، الفرونت بيبعتها
     * من صفحة /staff-access)، ومبتدخلش من /auth/login العادية. والعكس: أي دور تاني
     * مبيدخلش من بوابة الستاف. الرفض بنفس رسالة "بيانات غلط" عشان مفيش حد يعرف
     * إن الحساب ده موجود أو إنه ستاف.
     */
    private const STAFF_ROLES = ['admin', 'data_analyst', 'security_admin', 'security_officer'];

    /**
     * أنواع الحسابات في اللوجين العادي. الفرونت بيسأل "إنت مين؟" قبل الدخول وبيبعت
     * الاختيار في login_as؛ لو الحساب مش من النوع ده الدخول بيترفض حتى بإيميل
     * وباسورد صح (وبرضه لو login_as ناقص — مفيش دخول من غير اختيار).
     */
    private const PUBLIC_ROLE_LABELS = [
        'student'        => ['en' => 'Student',         'ar' => 'طالب'],
        'university'     => ['en' => 'University',      'ar' => 'جامعة'],
        'faculty'        => ['en' => 'Faculty',         'ar' => 'كلية'],
        'academic_staff' => ['en' => 'Academic staff',  'ar' => 'دكتور / عضو هيئة تدريس'],
        'supervisor'     => ['en' => 'Supervisor',      'ar' => 'مشرف'],
    ];

    public function __construct(
        private RoleService $roles,
        private AccountLockoutService $lockout,
        private TrustedDeviceService $trustedDevice,
        private SettingRepository $settings,
        private \App\Services\SecurityAlertService $alerts
    ) {
    }

    public function submit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $data = $validator->validated();
        $locale = $request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en';

        $user = User::where('email', $data['email'])->first();

        // Account Lockout policy — يتفحص قبل كلمة السر بالظبط زي القديم،
        // عشان مستخدم مقفول ياخد نفس الرسالة أيًا كان اللي كتبه.
        if ($user) {
            $lockStatus = $this->lockout->checkStatus($user, $locale);
            if ($lockStatus['locked']) {
                SecurityLog::write('Login blocked - account locked', ['user_id' => $user->id, 'ip' => $request->ip()]);
                return response()->json(['success' => false, 'message' => $lockStatus['message']], 422);
            }
        }

        if (!$user || !password_verify($data['password'], $user->password_hash)) {
            if ($user) {
                $attempt = $this->lockout->registerFailedAttempt($user, $request->ip(), $locale);
                if ($attempt['just_locked']) {
                    return response()->json(['success' => false, 'message' => $attempt['message']], 422);
                }
            }
            SecurityLog::write('Failed login attempt', ['email' => $data['email'], 'ip' => $request->ip()]);
            $this->alerts->failedLogin($data['email'], $request->ip(), $user?->id);
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password.',
            ], 422);
        }

        if (in_array($user->status, ['suspended', 'banned'], true)) {
            SecurityLog::write('Blocked login for ' . $user->status . ' account', ['user_id' => $user->id, 'ip' => $request->ip()]);
            return response()->json([
                'success' => false,
                'message' => 'This account has been ' . $user->status . '. Contact support.',
            ], 422);
        }

        $role = $this->roles->primaryRoleFor($user->id);

        $viaStaffPortal = $request->input('portal') === 'staff';
        if (in_array($role, self::STAFF_ROLES, true) !== $viaStaffPortal) {
            SecurityLog::write('Login refused - wrong entry portal', [
                'user_id' => $user->id, 'role' => $role, 'portal' => $viaStaffPortal ? 'staff' : 'public', 'ip' => $request->ip(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password.',
            ], 422);
        }

        if (!$viaStaffPortal) {
            $loginAs = (string) $request->input('login_as', '');
            if (!isset(self::PUBLIC_ROLE_LABELS[$loginAs])) {
                return response()->json([
                    'success' => false,
                    'message' => $locale === 'ar' ? 'اختار نوع حسابك الأول.' : 'Please choose your account type first.',
                ], 422);
            }
            if ($loginAs !== $role) {
                SecurityLog::write('Login refused - wrong account type chosen', [
                    'user_id' => $user->id, 'role' => $role, 'chosen' => $loginAs, 'ip' => $request->ip(),
                ]);
                $label = self::PUBLIC_ROLE_LABELS[$loginAs][$locale];
                return response()->json([
                    'success' => false,
                    'message' => $locale === 'ar'
                        ? "الحساب ده مش حساب «{$label}». اختار نوع الحساب الصح وجرّب تاني."
                        : "This isn't a {$label} account. Pick the right account type and try again.",
                ], 422);
            }
        }

        $this->lockout->registerSuccessfulLogin($user);

        if ($role !== 'admin' && $this->settings->get('maintenance_mode', 'global', null, '0') === '1') {
            SecurityLog::write('Login blocked - maintenance mode', ['user_id' => $user->id, 'ip' => $request->ip()]);
            return response()->json([
                'success' => false,
                'message' => $locale === 'ar' ? 'المنصة تحت الصيانة حالياً' : 'The platform is under maintenance',
                'data'    => ['maintenance_mode' => true],
                'errors'  => null,
                'meta'    => (object) [],
            ], 503);
        }

        if ($user->two_factor_enabled) {
            $trustedHit = $this->trustedDevice->isCurrentDeviceTrusted(
                $user->id,
                $request->ip(),
                $request->cookie(TrustedDeviceService::COOKIE_NAME)
            );

            if (!$trustedHit) {
                $challengeToken = UipJwtService::encode(
                    ['typ' => '2fa_challenge', 'sub' => $user->id, 'role' => $role],
                    300 // 5 دقايق — مدة كافية تدخل التطبيق وتكتب الكود
                );

                SecurityLog::write('Password verified, awaiting 2FA code', ['user_id' => $user->id, 'ip' => $request->ip()]);

                return response()->json([
                    'success' => true,
                    'message' => 'Two-factor verification required.',
                    'data'    => [
                        'requires_2fa'    => true,
                        'redirect'        => '/auth/two-factor',
                        'challenge_token' => $challengeToken,
                    ],
                    'errors' => null,
                    'meta'   => (object) [],
                ]);
            }

            SecurityLog::write('2FA skipped - trusted device', ['user_id' => $user->id, 'ip' => $request->ip()]);
        }

        DB::table('users')->where('id', $user->id)->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $tokens = UipJwtService::issueTokenPair($user->id, $role);

        SecurityLog::write('Successful login', ['user_id' => $user->id, 'ip' => $request->ip()]);

        return AuthCookies::attach(response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data'    => array_merge(['redirect' => $this->roles->homeRouteFor($role)], $tokens),
            'errors'  => null,
            'meta'    => (object) [],
        ]), $request);
    }
}
