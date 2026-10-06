<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Repositories\SettingRepository;
use App\Services\AccountLockoutService;
use App\Services\RoleService;
use App\Services\TrustedDeviceService;
use App\Services\UipJwtService;
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

        $this->lockout->registerSuccessfulLogin($user);
        $role = $this->roles->primaryRoleFor($user->id);

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

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data'    => array_merge(['redirect' => $this->roles->homeRouteFor($role)], $tokens),
            'errors'  => null,
            'meta'    => (object) [],
        ]);
    }
}
