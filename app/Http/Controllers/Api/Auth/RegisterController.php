<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PasswordPolicyService;
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
    /** الأدوار المتاحة للتسجيل العام فقط — نفس roleLabels() في RoleService القديمة. */
    private const SELF_REGISTER_ROLES = ['student', 'university'];

    public function __construct(
        private PasswordPolicyService $passwordPolicy,
        private StudentJoinRequestService $joinRequests,
        private \App\Services\DeviceRestrictionPolicyService $deviceRestriction
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
            'full_name' => 'required|min:2|max:150',
            'email'     => 'required|email',
            'password'  => 'required|min:8|confirmed',
            'role'      => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $data = $validator->validated();

        if (!in_array($data['role'], self::SELF_REGISTER_ROLES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Please choose a valid account type.',
            ], 422);
        }

        if (User::where('email', $data['email'])->exists()) {
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

        $user = DB::transaction(function () use ($data, $university_id, $faculty_id, $department_id, $program_id) {
            $newHash = password_hash($data['password'], PASSWORD_BCRYPT);

            $user = User::create([
                'uuid'                => (string) Str::uuid(),
                'full_name'           => $data['full_name'],
                'email'               => $data['email'],
                'phone'               => request()->input('phone'),
                'password_hash'       => $newHash,
                'preferred_language'  => request()->input('preferred_language', 'ar'),
                'status'              => 'pending',
            ]);

            $roleId = DB::table('roles')->where('slug', $data['role'])->value('id');
            if ($roleId) {
                DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => $roleId]);
            }

            $this->provisionRoleProfile($user->id, $data['role'], $data['full_name']);
            $this->passwordPolicy->recordPasswordChange($user->id, $newHash);

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

        // TODO: إصدار email_verification_tokens + إرسال إيميل التحقق
        // (AuthService::createEmailVerificationToken + MailService).

        SecurityLog::write('User registered', ['user_id' => $user->id, 'email' => $user->email, 'role' => $data['role']]);

        return response()->json([
            'success' => true,
            'message' => 'Account created successfully.',
            'data'    => ['user_id' => $user->id],
            'errors'  => null,
            'meta'    => (object) [],
        ], 201);
    }

    /** يطابق UserRepository::provisionRoleProfile() القديم — صف بروفايل فاضي حسب الدور. */
    private function provisionRoleProfile(int $userId, string $role, string $fullName): void
    {
        switch ($role) {
            case 'student':
                DB::table('students')->insert(['user_id' => $userId]);
                break;
            case 'university':
                DB::table('universities')->insert([
                    'user_id' => $userId, 'official_name_ar' => $fullName,
                    'official_name_en' => $fullName, 'country' => '',
                ]);
                break;
        }
    }
}
