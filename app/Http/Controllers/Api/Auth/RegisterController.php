<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmailVerificationService;
use App\Services\PasswordPolicyService;
use App\Services\RoleService;
use App\Services\StudentJoinRequestService;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * يطابق RegisterController::submit() + AuthService::register() القديمين،
 * وبقى فيه تعقيد كلمة السر الحقيقي (PasswordPolicyService).
 *
 * ⚠️ الأدوار المسموح بالتسجيل العام فيها (roleLabels() القديمة) — عن قصد
 * ناقصة admin/security_admin/security_officer/data_analyst، دول
 * invite-only بس، زي القديم بالظبط.
 */
class RegisterController extends Controller
{
    /** الأدوار المتاحة للتسجيل العام فقط — الطالب بس. حسابات الجامعات بيضيفها الأدمن (Admin → Users) ومبتتسجّلش ذاتيًا. */
    private const SELF_REGISTER_ROLES = ['student'];

    public function __construct(
        private PasswordPolicyService $passwordPolicy,
        private StudentJoinRequestService $joinRequests,
        private \App\Services\DeviceRestrictionPolicyService $deviceRestriction,
        private EmailVerificationService $verification,
        private RoleService $roles
    ) {
    }

    public function submit(Request $request)
    {
        // Device Restrictions Policy — نوع جهاز محظور مايقدرش يعمل حساب كمان (مش اللوجين بس).
        // UipDeviceRestrictionMiddleware بيغطي ده أصلًا؛ الفحص هنا طبقة تانية لو الميدلوير اتشال.
        if (!$this->deviceRestriction->isAllowed($request->userAgent())) {
            SecurityLog::write('Register blocked - device type restricted', [
                'device' => $this->deviceRestriction->classify($request->userAgent()), 'ip' => $request->ip(),
            ]);
            return response()->json([
                'success' => false,
                'message' => $this->deviceRestriction->blockedMessage($request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en'),
                'data'    => ['device_blocked' => true],
                'errors'  => ['code' => 'device_blocked'],
                'meta'    => (object) [],
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'email'     => 'required|email',
            'password'  => 'required|min:8|confirmed',
            'role'      => 'required|string',
        ]);

        // Every account carries both an Arabic and an English name.
        $names = \App\Support\BilingualName::fromRequest($request, $request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en');

        if ($validator->fails() || !$names['ok']) {
            $errors = $names['errors'];
            foreach ($validator->errors()->toArray() as $field => $messages) {
                $errors[$field] = $messages[0];
            }
            return response()->json([
                'success' => false,
                'message' => $names['errors'] ? reset($names['errors']) : $validator->errors()->first(),
                'errors'  => $errors,
            ], 422);
        }

        $data = $validator->validated();
        $data['full_name'] = $names['full_name'];
        $data['name_ar']   = $names['name_ar'];
        $data['name_en']   = $names['name_en'];

        if ($data['role'] === 'university') {
            SecurityLog::write('Register refused - university accounts are admin-created', ['ip' => $request->ip()]);
            return response()->json([
                'success' => false,
                'message' => $request->header('X-Locale', 'en') === 'ar'
                    ? 'حسابات الجامعات بيضيفها مدير المنصة فقط، ومينفعش التسجيل بنفسك.'
                    : 'University accounts are created by the platform administrator and cannot be self-registered.',
                'data'    => null,
                'errors'  => ['code' => 'university_self_register_disabled'],
                'meta'    => (object) [],
            ], 403);
        }

        if (!in_array($data['role'], self::SELF_REGISTER_ROLES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Please choose a valid account type.',
            ], 422);
        }

        // Device Restrictions Policy (وضع by_role) — نوع الحساب المختار للتسجيل (طالب).
        if (!$this->deviceRestriction->isAllowed($request->userAgent(), $data['role'])) {
            $locale = $request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en';
            SecurityLog::write('Register blocked - device type restricted for role', [
                'role' => $data['role'], 'device' => $this->deviceRestriction->classify($request->userAgent()), 'ip' => $request->ip(),
            ]);
            return response()->json([
                'success' => false,
                'message' => $this->deviceRestriction->blockedMessage($locale, $data['role']),
                'data'    => [
                    'device_blocked' => true,
                    'device_type'    => $this->deviceRestriction->classify($request->userAgent()),
                    'role'           => $data['role'],
                ],
                'errors'  => ['code' => 'device_blocked'],
                'meta'    => (object) [],
            ], 403);
        }

        $existing = User::whereRaw('LOWER(email) = ?', [strtolower($data['email'])])->first();
        // حساب سابق لسه pending ومتأكدش إيميله ومفيش جوجل مربوط بيه: محدش أثبت إنه بتاعه أصلًا (ممكن حد سجّل
        // بإيميل مش بتاعه). فنسمح لصاحب الإيميل الحقيقي يسجّل فوقه بدل ما نقفل الإيميل عليه، والتأكيد بيروح
        // للإيميل نفسه فمحدش غيره يقدر يفعّله.
        $reclaimable = $existing
            && EmailVerificationService::blocksLogin($existing)
            && !$this->hasSocialAccount((int) $existing->id)
            && $this->roles->primaryRoleFor($existing->id) === 'student';
        if ($existing && !$reclaimable) {
            return response()->json([
                'success' => false,
                'message' => 'An account with this email already exists.',
            ], 422);
        }

        try {
            $this->passwordPolicy->assertValid($data['password']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $university_id = $request->input('university_id');
        $faculty_id    = $request->input('faculty_id');
        $department_id = $request->input('department_id');
        $program_id    = $request->input('program_id');

        $user = DB::transaction(function () use ($data, $university_id, $faculty_id, $department_id, $program_id, $reclaimable, $existing) {
            $newHash = password_hash($data['password'], PASSWORD_BCRYPT);

            if ($reclaimable) {
                // نعيد استخدام صف الحساب المحجوز نفسه (من غير مسح — فيه جداول سجلات بتشاور عليه): بيانات جديدة
                // كاملة وباسورد جديد، ونقفل أي جلسة/لينك قديم. الدور وصف الطالب موجودين أصلًا.
                DB::table('refresh_tokens')->where('user_id', $existing->id)->delete();
                DB::table('password_reset_tokens')->where('user_id', $existing->id)->whereNull('used_at')->update(['used_at' => now()]);
                $existing->forceFill([
                    'full_name'          => $data['full_name'],
                    'name_ar'            => $data['name_ar'],
                    'name_en'            => $data['name_en'],
                    'phone'              => request()->input('phone'),
                    'password_hash'      => $newHash,
                    'preferred_language' => request()->input('preferred_language', 'ar'),
                    'status'             => 'pending',
                    'email_verified_at'  => null,
                ])->save();
                $user = $existing->fresh();
                $this->passwordPolicy->recordPasswordChange($user->id, $newHash);
            } else {
                $user = User::create([
                    'uuid'                => (string) Str::uuid(),
                    'full_name'           => $data['full_name'],
                    'name_ar'             => $data['name_ar'],
                    'name_en'             => $data['name_en'],
                    'email'               => $data['email'],
                    'phone'               => request()->input('phone'),
                    'password_hash'       => $newHash,
                    'preferred_language'  => request()->input('preferred_language', 'ar'),
                    'status'              => 'pending',
                ]);
            }

            if (!$reclaimable) {
                $roleId = DB::table('roles')->where('slug', $data['role'])->value('id');
                if ($roleId) {
                    DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => $roleId]);
                }

                $this->provisionRoleProfile($user->id, $data['role'], $data['name_ar'], $data['name_en']);
                $this->passwordPolicy->recordPasswordChange($user->id, $newHash);
            }

            // لو الدور student واختار جامعة في الفورم (مش "Decide later")،
            // ابعت طلب الانضمام على طول — best-effort زي القديم: فشل هنا
            // (جامعة مش موجودة، مثلًا) ميوقفش التسجيل، الطالب لسه يقدر
            // يبعت الطلب تاني من صفحة البروفايل بتاعته.
            if ($data['role'] === 'student' && $university_id) {
                try {
                    $this->joinRequests->submitRequest(
                        $user->id,
                        (int) $university_id,
                        $faculty_id ? (int) $faculty_id : null,
                        $department_id ? (int) $department_id : null,
                        $program_id ? (int) $program_id : null
                    );
                } catch (\Throwable $e) {
                    Log::warning('Join request on registration failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }

            return $user;
        });

        // الحساب pending: مبيدخلش لحد ما صاحب الإيميل يضغط لينك التأكيد (أو الأدمن يفعّله).
        $emailSent = $this->verification->issueAndSend($user);

        SecurityLog::write('User registered', ['user_id' => $user->id, 'email' => $user->email, 'role' => $data['role'], 'reclaimed' => (bool) $reclaimable]);

        $ar = $request->header('X-Locale', 'en') === 'ar';
        return response()->json([
            'success' => true,
            'message' => $ar
                ? 'تم إنشاء الحساب. بعتنالك رسالة على بريدك — اضغط على رابط التأكيد عشان تقدر تدخل.'
                : 'Account created. We emailed you a confirmation link — confirm your email to sign in.',
            'data'    => ['user_id' => $user->id, 'requires_verification' => true, 'email_sent' => $emailSent, 'email' => $user->email],
            'errors'  => null,
            'meta'    => (object) [],
        ], 201);
    }

    /** لو جدول user_social_accounts لسه متعملوش (migration ما اتشغلتش) منوقّعش التسجيل بـ 500 — نعتبر مفيش حساب جوجل مربوط. */
    private function hasSocialAccount(int $userId): bool
    {
        try {
            return DB::table('user_social_accounts')->where('user_id', $userId)->exists();
        } catch (\Throwable $e) {
            Log::warning('user_social_accounts lookup failed (run php artisan migrate)', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /** يطابق UserRepository::provisionRoleProfile() القديم — صف بروفايل فاضي حسب الدور. */
    private function provisionRoleProfile(int $userId, string $role, string $nameAr, string $nameEn): void
    {
        switch ($role) {
            case 'student':
                DB::table('students')->insert(['user_id' => $userId]);
                break;
        }
    }
}
