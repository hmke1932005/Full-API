<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\SecurityPolicyRepository;
use App\Services\AccountLockoutService;
use App\Services\ApiRateLimitPolicyService;
use App\Services\CountryRestrictionPolicyService;
use App\Services\DeviceRestrictionPolicyService;
use App\Services\FileUploadPolicyService;
use App\Services\IpRestrictionPolicyService;
use App\Services\MfaPolicyService;
use App\Services\PasswordPolicyService;
use App\Services\SessionPolicyService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/SecurityPoliciesApiController.php
 * القديمة — سطح /api/v1/security/policies/* بالكامل، بند 25 batch 3
 * (Vulnerabilities + Policies). سياسة أمان واحدة (باسورد/lockout/رفع
 * ملفات/جلسة/MFA/rate-limit/IP/دولة/جهاز) + القائمة العامة key/value +
 * فتح قفل حساب يدوي — بتعيد استخدام SecurityPolicyRepository وكل
 * *PolicyService بالظبط زي القديمة.
 *
 * RBAC: كل موظفي الأمان (security_admin/security_officer/admin) يقدروا
 * يشوفوا (index)، بس security_admin/admin بس يقدروا يعدّلوا — نفس
 * canEdit() القديمة بالظبط.
 *
 * فرق شكلي فقط عن القديمة: Session::userId()/userRole() -> $request
 * ->attributes->get('uip_user_id')/'uip_role'.
 */
class SecurityPoliciesApiController extends Controller
{
    public function __construct(
        private SecurityPolicyRepository $policies,
        private AccountLockoutService $lockout,
        private PasswordPolicyService $passwordPolicy,
        private FileUploadPolicyService $uploadPolicy,
        private SessionPolicyService $sessionPolicy,
        private MfaPolicyService $mfaPolicy,
        private ApiRateLimitPolicyService $rateLimitPolicy,
        private IpRestrictionPolicyService $ipRestrictionPolicy,
        private CountryRestrictionPolicyService $countryRestrictionPolicy,
        private DeviceRestrictionPolicyService $deviceRestrictionPolicy
    ) {
    }

    /** GET /api/v1/security/policies — كل الپانلات المهيكلة + القائمة العامة key/value، يقدر يشوفها أي موظف أمان. */
    public function index(Request $request)
    {
        if (!$this->isSecurityStaff($request)) {
            return $this->apiError('Only Security Portal staff can view policies.', null, 403);
        }

        $excludedKeys = [
            'login.lockout_policy', 'password.policy', 'upload.policy', 'session.policy',
            'mfa.policy', 'rate_limit.policy', 'ip_restriction.policy',
            'country_restriction.policy', 'device_restriction.policy',
        ];
        $generic = array_values(array_map(
            fn ($p) => $p->toArray(),
            array_filter(
                $this->policies->all(),
                fn ($p) => !in_array($p->policy_key, $excludedKeys, true)
            )
        ));

        return $this->apiSuccess([
            'generic_policies'           => $generic,
            'can_edit'                   => $this->canEdit($request),
            'lockout_policy'             => $this->lockout->getPolicy(),
            'locked_accounts'            => $this->lockout->lockedAccounts(),
            'password_policy'            => $this->passwordPolicy->getPolicy(),
            'upload_policy'              => $this->uploadPolicy->getPolicy(),
            'session_policy'             => $this->sessionPolicy->getPolicy(),
            'mfa_policy'                 => $this->mfaPolicy->getPolicy(),
            'mfa_enforceable_roles'      => MfaPolicyService::ENFORCEABLE_ROLES,
            'rate_limit_policy'          => $this->rateLimitPolicy->getPolicy(),
            'ip_restriction_policy'      => $this->ipRestrictionPolicy->getPolicy(),
            'country_restriction_policy' => $this->countryRestrictionPolicy->getPolicy(),
            'device_restriction_policy'  => $this->deviceRestrictionPolicy->getPolicy(),
        ], 'Policies retrieved successfully.');
    }

    /** PATCH /api/v1/security/policies — تحديث عام لسياسة key/value واحدة. */
    public function update(Request $request)
    {
        if (!$this->canEdit($request)) {
            return $this->apiError('Only a Security Administrator can change policies.', null, 403);
        }

        $key = (string) $request->input('policy_key', '');
        $value = (string) $request->input('value', '');

        if ($key === '' || !$this->policies->updateValue($key, $value, $request->attributes->get('uip_user_id'))) {
            return $this->apiError('Could not update that policy.', null, 422);
        }

        return $this->apiSuccess(null, 'Policy updated.');
    }

    /** PATCH /api/v1/security/policies/lockout */
    public function updateLockoutPolicy(Request $request)
    {
        return $this->applyStructuredPolicy($request, $this->lockout, 'Failed login / lockout policy updated.');
    }

    /** PATCH /api/v1/security/policies/password */
    public function updatePasswordPolicy(Request $request)
    {
        return $this->applyStructuredPolicy($request, $this->passwordPolicy, 'Password complexity / expiration policy updated.');
    }

    /** PATCH /api/v1/security/policies/upload */
    public function updateUploadPolicy(Request $request)
    {
        return $this->applyStructuredPolicy($request, $this->uploadPolicy, 'File upload restrictions policy updated.');
    }

    /** PATCH /api/v1/security/policies/session */
    public function updateSessionPolicy(Request $request)
    {
        return $this->applyStructuredPolicy($request, $this->sessionPolicy, 'Session timeout / concurrent session policy updated.');
    }

    /** PATCH /api/v1/security/policies/mfa */
    public function updateMfaPolicy(Request $request)
    {
        return $this->applyStructuredPolicy($request, $this->mfaPolicy, 'MFA requirements policy updated.');
    }

    /** PATCH /api/v1/security/policies/rate-limit */
    public function updateRateLimitPolicy(Request $request)
    {
        return $this->applyStructuredPolicy($request, $this->rateLimitPolicy, 'API rate limit policy updated.');
    }

    /** PATCH /api/v1/security/policies/ip-restriction */
    public function updateIpRestrictionPolicy(Request $request)
    {
        return $this->applyStructuredPolicy($request, $this->ipRestrictionPolicy, 'IP restriction policy updated.');
    }

    /** PATCH /api/v1/security/policies/country-restriction */
    public function updateCountryRestrictionPolicy(Request $request)
    {
        return $this->applyStructuredPolicy($request, $this->countryRestrictionPolicy, 'Country restriction policy updated.');
    }

    /** PATCH /api/v1/security/policies/device-restriction */
    public function updateDeviceRestrictionPolicy(Request $request)
    {
        return $this->applyStructuredPolicy($request, $this->deviceRestrictionPolicy, 'Device restriction policy updated.');
    }

    /** POST /api/v1/security/policies/unlock/{id} — فتح قفل يدوي من مسؤول أمان. */
    public function unlockAccount(Request $request, $id)
    {
        if (!$this->canEdit($request)) {
            return $this->apiError('Only a Security Administrator can unlock accounts.', null, 403);
        }

        if (!$id) {
            return $this->apiError('A user id is required.', null, 422);
        }

        $this->lockout->manualUnlock($id, $request->attributes->get('uip_user_id'), $request->ip());

        return $this->apiSuccess(null, 'Account unlocked.');
    }

    // -- helpers --------------------------------------------------------------

    /** Shared apply/error handling لكل نداء *PolicyService::updatePolicy(). */
    private function applyStructuredPolicy(Request $request, object $service, string $successMessage)
    {
        if (!$this->canEdit($request)) {
            return $this->apiError('Only a Security Administrator can change policies.', null, 403);
        }

        try {
            // الـ 4th arg (User-Agent) بيستخدمه Device policy بس لحماية الأدمن من قفل نفسه؛ باقي الـ services بتتجاهله.
            $service->updatePolicy($request->all(), $request->attributes->get('uip_user_id'), $request->ip(), $request->userAgent());
            return $this->apiSuccess(null, $successMessage);
        } catch (\InvalidArgumentException $e) {
            return $this->apiError($e->getMessage(), null, 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Security policy save failed', [
                'policy' => get_class($service),
                'error'  => $e->getMessage(),
            ]);
            return $this->apiError('Could not save that policy.', null, 422);
        }
    }

    private function isSecurityStaff(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['security_admin', 'security_officer', 'admin'], true);
    }

    private function canEdit(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['security_admin', 'admin'], true);
    }
}
