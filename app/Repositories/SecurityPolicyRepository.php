<?php

namespace App\Repositories;

use App\Models\SecurityPolicy;

/**
 * منقولة من app/Repositories/SecurityPolicyRepository.php القديمة بالكامل
 * — بند 25 batch 3 (Vulnerabilities + Policies). Data access لـ
 * `security_policies` (migration 041)، بيستخدمها كل *PolicyService
 * (Password/AccountLockout/Session/Mfa/ApiRateLimit/FileUpload/
 * IpRestriction/CountryRestriction/DeviceRestriction) بنفس النمط:
 * findByKey() لقراءة الـ JSON المخزّن، updateValue() للحفظ + تسجيل
 * updated_by.
 */
class SecurityPolicyRepository
{
    /** @return SecurityPolicy[] كل الصفوف، مرتبة زي القديمة (category ثم policy_key) */
    public function all(): array
    {
        return SecurityPolicy::orderBy('category')->orderBy('policy_key')->get()->all();
    }

    public function findByKey(string $key): ?SecurityPolicy
    {
        return SecurityPolicy::where('policy_key', $key)->first();
    }

    public function updateValue(string $key, string $value, $updatedBy): bool
    {
        $policy = $this->findByKey($key);
        if (!$policy) {
            return false;
        }
        $policy->fill(['value' => $value, 'updated_by' => $updatedBy]);
        return $policy->save();
    }
}
