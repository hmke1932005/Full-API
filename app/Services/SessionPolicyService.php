<?php

namespace App\Services;

/**
 * منقولة من app/Services/SessionPolicyService.php القديمة — بند 25
 * batch 3 (Vulnerabilities + Policies). getPolicy()/updatePolicy() بس
 * (اللي SecurityPoliciesApiController محتاجاها). enforceIdleTimeout()/
 * enforceConcurrentLimit() القديمة (بتتنادى من AuthMiddleware/AuthService
 * وقت كل ريكوست/لوجين) مش منقولة هنا عمدًا — نفس فجوة UserSessionRepository
 * الموثّقة في بند 25 batch 1: AuthService/AuthMiddleware في اللارافيل
 * لسه مش بيكتبوا/يتحققوا من `user_sessions` أصلًا، فمفيش حاجة حقيقية
 * تستدعيهم دلوقتي — هتتضاف لما توصيل تتبع الجلسة جوه Auth يتعمل فعليًا.
 */
class SessionPolicyService
{
    private const POLICY_KEY = 'session.policy';

    private const DEFAULTS = [
        'timeout_minutes'          => 60,
        'concurrent_limit'         => 0,
        'enforce_concurrent_limit' => false,
    ];

    public function __construct(
        private \App\Repositories\SecurityPolicyRepository $policies,
        private AuditLogService $auditLog
    ) {
    }

    /** @return array{timeout_minutes:int,concurrent_limit:int,enforce_concurrent_limit:bool} */
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
        return array_merge(self::DEFAULTS, $stored);
    }

    /** @throws \InvalidArgumentException on bad input */
    public function updatePolicy(array $input, $adminUserId, ?string $ip = null): array
    {
        $before = $this->getPolicy();

        $timeout = (int) ($input['timeout_minutes'] ?? 0);
        if ($timeout < 1 || $timeout > 43200) {
            throw new \InvalidArgumentException('Session timeout must be between 1 minute and 43200 minutes (30 days).');
        }

        $limit = (int) ($input['concurrent_limit'] ?? 0);
        if ($limit < 0 || $limit > 50) {
            throw new \InvalidArgumentException('Concurrent session limit must be between 0 (unlimited) and 50.');
        }

        $enforce = !empty($input['enforce_concurrent_limit']);
        if ($enforce && $limit < 1) {
            throw new \InvalidArgumentException('Set a concurrent session limit of at least 1 before enabling enforcement.');
        }

        $policy = [
            'timeout_minutes'          => $timeout,
            'concurrent_limit'         => $limit,
            'enforce_concurrent_limit' => $enforce,
        ];

        $saved = $this->policies->updateValue(self::POLICY_KEY, json_encode($policy, JSON_UNESCAPED_UNICODE), $adminUserId);
        if (!$saved) {
            throw new \RuntimeException('Could not save the session policy.');
        }

        $this->auditLog->record($adminUserId, 'security_policy.session_updated', 'SecurityPolicy', null, $before, $policy, $ip);

        return $policy;
    }
}
