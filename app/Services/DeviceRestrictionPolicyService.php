<?php

namespace App\Services;

use App\Repositories\SecurityPolicyRepository;

/**
 * منقولة من app/Services/DeviceRestrictionPolicyService.php القديمة
 * بالكامل — بند 25 batch 3. classify()/isAllowed() منقولين كاملين.
 *
 * الإنفاذ (enforcement): isAllowed() بتتنادى من
 *   - LoginController::submit()          (منع الدخول من نوع جهاز محظور)
 *   - RefreshTokenController::submit()   (منع تجديد جلسة على جهاز محظور)
 *   - UipAuthMiddleware                  (كل ريكوست محمي — يقطع الجلسات الشغالة فورًا)
 *   - UipDeviceRestrictionMiddleware     (كل ريكوست /api/* بما فيه register والـ endpoints العامة)
 *   - RegisterController::submit()       (طبقة تانية على إنشاء الحساب)
 *   - DeviceStatusController             (GET /v1/device-status — الفرونت يعرض صفحة الحظر)
 * التصنيف مبني على User-Agent، يعني بيمنع الاستخدام العادي من الجهاز
 * بس مش حماية قوية ضد حد بيزوّر الـ User-Agent عمدًا.
 */
class DeviceRestrictionPolicyService
{
    private const POLICY_KEY = 'device_restriction.policy';

    public const DEVICE_TYPES = ['desktop', 'mobile', 'tablet', 'unknown'];

    private const DEFAULTS = [
        'mode'                 => 'disabled',
        'allowed_device_types' => [],
        'blocked_device_types' => [],
    ];

    public function __construct(
        private SecurityPolicyRepository $policies,
        private AuditLogService $auditLog
    ) {
    }

    /** @return array{mode:string,allowed_device_types:string[],blocked_device_types:string[]} */
    public function getPolicy(): array
    {
        $row = $this->policies->findByKey(self::POLICY_KEY);
        $stored = [];
        if ($row) {
            $decoded = json_decode((string) $row->value, true);
            if (is_array($decoded)) {
                $stored = $decoded;
            }
        }
        $policy = array_merge(self::DEFAULTS, $stored);
        $policy['allowed_device_types'] = array_values(array_intersect((array) $policy['allowed_device_types'], self::DEVICE_TYPES));
        $policy['blocked_device_types'] = array_values(array_intersect((array) $policy['blocked_device_types'], self::DEVICE_TYPES));
        return $policy;
    }

    /**
     * @param string|null $userAgent الـ User-Agent بتاع الأدمن اللي بيحفظ السياسة — لو السياسة
     *                               الجديدة هتحظر جهازه هو نفسه بنرفض الحفظ (حماية من قفل الأدمن برا).
     * @throws \InvalidArgumentException on bad input
     */
    public function updatePolicy(array $input, $adminUserId, ?string $ip = null, ?string $userAgent = null): array
    {
        $before = $this->getPolicy();

        $mode = (string) ($input['mode'] ?? 'disabled');
        if (!in_array($mode, ['disabled', 'allowlist', 'denylist'], true)) {
            throw new \InvalidArgumentException('Invalid device restriction mode.');
        }

        $allowed = array_values(array_intersect((array) ($input['allowed_device_types'] ?? []), self::DEVICE_TYPES));
        $blocked = array_values(array_intersect((array) ($input['blocked_device_types'] ?? []), self::DEVICE_TYPES));

        if ($mode === 'allowlist' && !$allowed) {
            throw new \InvalidArgumentException('Select at least one device type before enabling allowlist mode.');
        }
        if ($mode === 'denylist' && !$blocked) {
            throw new \InvalidArgumentException('Select at least one device type before enabling denylist mode.');
        }

        $policy = ['mode' => $mode, 'allowed_device_types' => $allowed, 'blocked_device_types' => $blocked];

        if ($userAgent !== null && !$this->allows($policy, $userAgent)) {
            throw new \InvalidArgumentException(
                'This policy would block the device you are using right now (' . $this->classify($userAgent) . '), and you would lose access. Save it from a device type that stays allowed.'
            );
        }

        $saved = $this->policies->updateValue(self::POLICY_KEY, json_encode($policy, JSON_UNESCAPED_UNICODE), $adminUserId);
        if (!$saved) {
            throw new \RuntimeException('Could not save the device restriction policy.');
        }

        $this->auditLog->record($adminUserId, 'security_policy.device_restriction_updated', 'SecurityPolicy', null, $before, $policy, $ip);

        return $policy;
    }

    public function classify(?string $userAgent): string
    {
        if (!$userAgent) {
            return 'unknown';
        }

        if (preg_match('/iPad|Android(?!.*Mobile)|Tablet|Kindle|PlayBook/i', $userAgent)) {
            return 'tablet';
        }
        if (preg_match('/Mobile|iPhone|iPod|Android|BlackBerry|IEMobile|Opera Mini/i', $userAgent)) {
            return 'mobile';
        }
        if (preg_match('/Windows|Macintosh|Mac OS X|X11|Linux/i', $userAgent)) {
            return 'desktop';
        }

        return 'unknown';
    }

    /** True = هذا النوع من الأجهزة مسموح له يكمل اللوجين، حسب السياسة الحالية. */
    public function isAllowed(?string $userAgent): bool
    {
        return $this->allows($this->getPolicy(), $userAgent);
    }

    /** نفس منطق isAllowed() على سياسة معيّنة (عشان نقدر نفحص سياسة لسه ماتحفظتش). */
    private function allows(array $policy, ?string $userAgent): bool
    {
        if ($policy['mode'] === 'disabled') {
            return true;
        }

        $type = $this->classify($userAgent);

        if ($policy['mode'] === 'allowlist') {
            return in_array($type, $policy['allowed_device_types'], true);
        }

        return !in_array($type, $policy['blocked_device_types'], true);
    }

    /** رسالة الرفض للمستخدم (عربي/إنجليزي حسب X-Locale). */
    public function blockedMessage(string $locale = 'en'): string
    {
        return $locale === 'ar'
            ? 'استخدام المنصة من هذا النوع من الأجهزة غير مسموح حاليًا. جرّب من جهاز آخر أو تواصل مع الدعم.'
            : 'Using the platform from this type of device is not allowed. Try another device or contact support.';
    }
}
