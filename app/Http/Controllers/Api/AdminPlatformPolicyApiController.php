<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ResponseCryptoService;
use App\Support\DisposableEmail;
use App\Support\PlatformPolicy;
use Illuminate\Http\Request;

/**
 * Admin → Platform Policy. One JSON surface for the switches an admin wants without a deploy:
 *
 *   GET    /api/v1/admin/platform-policy            current switches, block / allow lists, server facts
 *   PATCH  /api/v1/admin/platform-policy/flags      { flags: { <key>: bool, ... } }
 *   POST   /api/v1/admin/platform-policy/rules      { list: block|allow, values: "a, b\nc" | [..], note? }
 *   DELETE /api/v1/admin/platform-policy/rules      { list, value }
 *   POST   /api/v1/admin/platform-policy/test       { email }  -> would this address be refused, and why
 *   GET    /api/v1/platform/status                  (public) { registration_enabled, response_encryption }
 *
 * Everything lives in the `settings` table (see App\Support\PlatformPolicy). Every change is audit-logged.
 * RBAC: uip.auth + uip.admin on the route group, plus the same uip_role === 'admin' guard as the other admin controllers.
 */
class AdminPlatformPolicyApiController extends Controller
{
    private const MAX_VALUES_PER_REQUEST = 500;

    public function __construct(private AuditLogService $auditLog)
    {
    }

    private function requireAdmin(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can manage the platform policy.', null, 403);
        }

        return null;
    }

    private function adminId(Request $request): int
    {
        return (int) $request->attributes->get('uip_user_id');
    }

    private function locale(Request $request): string
    {
        return $request->header('X-Locale', 'en') === 'ar' ? 'ar' : 'en';
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        $flags = PlatformPolicy::flags();
        $defaults = [];
        $overridden = [];
        foreach (array_keys(PlatformPolicy::FLAGS) as $k) {
            $defaults[$k] = PlatformPolicy::defaultFor($k);
            $overridden[$k] = PlatformPolicy::isOverridden($k);
        }

        $selfTest = null;
        try {
            $selfTest = app(ResponseCryptoService::class)->selfTest();
        } catch (\Throwable $e) {
            // leaves null: the page shows "unknown" instead of failing to load
        }

        return [
            'flags'      => $flags,
            'defaults'   => $defaults,
            'overridden' => $overridden,
            'rules'      => [
                PlatformPolicy::LIST_BLOCK => PlatformPolicy::rules(PlatformPolicy::LIST_BLOCK),
                PlatformPolicy::LIST_ALLOW => PlatformPolicy::rules(PlatformPolicy::LIST_ALLOW),
            ],
            'limits'     => ['max_rules' => PlatformPolicy::MAX_RULES, 'max_values_per_request' => self::MAX_VALUES_PER_REQUEST],
            // Hard switches in .env that sit above the dashboard: when false the dashboard cannot turn the feature on.
            'server'     => [
                'email_checks_master'  => DisposableEmail::enabled(),            // MAIL_BLOCK_DISPOSABLE
                'encryption_master'    => (bool) config('secure_transport.enabled', true), // RESPONSE_ENC_ENABLED
                'encryption_enforced'  => (bool) config('secure_transport.enforce', false), // RESPONSE_ENC_ENFORCE
                'encryption_selftest'  => $selfTest,
                'encryption_effective' => PlatformPolicy::encryptionOn(),
            ],
        ];
    }

    /** GET /api/v1/admin/platform-policy */
    public function show(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        return $this->apiSuccess($this->snapshot(), 'Platform policy retrieved successfully.');
    }

    /** PATCH /api/v1/admin/platform-policy/flags — { flags: { key: bool } } (a flat { key: bool } body works too). */
    public function updateFlags(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $input = $request->input('flags');
        if (!is_array($input)) {
            $input = $request->only(array_keys(PlatformPolicy::FLAGS));
        }
        if ($input === []) {
            return $this->apiError('No switches were sent.', null, 422);
        }
        $unknown = array_diff(array_keys($input), array_keys(PlatformPolicy::FLAGS));
        if ($unknown) {
            return $this->apiError('Unknown switch: ' . implode(', ', array_map('strval', $unknown)), ['flags' => array_values($unknown)], 422);
        }

        $before = PlatformPolicy::flags();
        $changed = [];
        foreach ($input as $key => $raw) {
            $value = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value === null) {
                return $this->apiError("The value of {$key} must be true or false.", ['flags' => [$key]], 422);
            }
            if ($before[$key] !== $value || !PlatformPolicy::isOverridden($key)) {
                PlatformPolicy::setFlag($key, $value);
            }
            if ($before[$key] !== $value) {
                $changed[$key] = $value;
            }
        }

        if ($changed) {
            $this->auditLog->record(
                $this->adminId($request),
                'platform.policy_flags_update',
                'Setting',
                null,
                array_intersect_key($before, $changed),
                $changed,
                $request->ip()
            );
        }

        return $this->apiSuccess($this->snapshot(), $changed ? 'Platform policy saved.' : 'Nothing changed.');
    }

    /** POST /api/v1/admin/platform-policy/rules */
    public function addRules(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $list = (string) $request->input('list', '');
        if (!in_array($list, [PlatformPolicy::LIST_BLOCK, PlatformPolicy::LIST_ALLOW], true)) {
            return $this->apiError('The list must be "block" or "allow".', ['list' => 'invalid'], 422);
        }

        $values = $request->input('values', $request->input('value'));
        if (is_string($values)) {
            $values = preg_split('/[\s,;]+/u', trim($values), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_array($values) || $values === []) {
            return $this->apiError('Enter at least one email address or domain.', ['values' => 'required'], 422);
        }
        $values = array_values(array_filter($values, 'is_scalar'));
        if (count($values) > self::MAX_VALUES_PER_REQUEST) {
            return $this->apiError('Too many entries in one request (max ' . self::MAX_VALUES_PER_REQUEST . ').', ['values' => 'too_many'], 422);
        }

        $adminId = $this->adminId($request);
        $me = User::find($adminId);
        $res = PlatformPolicy::addRules(
            $list,
            $values,
            $request->filled('note') ? (string) $request->input('note') : null,
            $adminId,
            $me && $me->email ? [$me->email] : []
        );

        if ($res['added']) {
            $this->auditLog->record(
                $adminId,
                'platform.policy_rules_add',
                'Setting',
                null,
                null,
                ['list' => $list, 'values' => array_column($res['added'], 'value')],
                $request->ip()
            );
        }

        $message = $res['added']
            ? count($res['added']) . ' rule(s) added.'
            : 'No new rules were added.';

        return $this->apiSuccess($this->snapshot() + ['result' => $res], $message);
    }

    /** DELETE /api/v1/admin/platform-policy/rules — { list, value } */
    public function removeRule(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $list = (string) $request->input('list', '');
        $value = (string) $request->input('value', '');
        if (!in_array($list, [PlatformPolicy::LIST_BLOCK, PlatformPolicy::LIST_ALLOW], true) || trim($value) === '') {
            return $this->apiError('The list and the rule are required.', null, 422);
        }

        if (!PlatformPolicy::removeRule($list, $value)) {
            return $this->apiError('That rule was not found.', null, 404);
        }

        $this->auditLog->record(
            $this->adminId($request),
            'platform.policy_rule_remove',
            'Setting',
            null,
            ['list' => $list, 'value' => mb_strtolower(trim($value))],
            null,
            $request->ip()
        );

        return $this->apiSuccess($this->snapshot(), 'Rule removed.');
    }

    /** POST /api/v1/admin/platform-policy/test — { email } → what the sign-up form would say for it (DNS included). */
    public function test(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $email = trim((string) $request->input('email', ''));
        if ($email === '' || mb_strlen($email) > 254 || !str_contains($email, '@')) {
            return $this->apiError('Enter a full email address to test.', ['email' => 'invalid'], 422);
        }

        $verdict = DisposableEmail::diagnose($email, $this->locale($request), true);

        return $this->apiSuccess([
            'email'   => mb_strtolower($email),
            'allowed' => $verdict['ok'],
            'code'    => $verdict['code'],
            'rule'    => $verdict['rule'],
            'message' => $verdict['message'],
        ], $verdict['ok'] ? 'This address would be accepted.' : 'This address would be refused.');
    }

    /** GET /api/v1/platform/status — public, tiny, no secrets: lets the SPA hide the sign-up form and know the channel mode. */
    public function publicStatus()
    {
        return $this->apiSuccess([
            'registration_enabled' => PlatformPolicy::registrationOpen(),
            'response_encryption'  => PlatformPolicy::encryptionOn(),
        ], 'OK')->header('Cache-Control', 'no-store');
    }
}
