<?php

namespace App\Support;

use App\Repositories\SettingRepository;
use Illuminate\Support\Facades\DB;

/**
 * Runtime platform switches the admin controls from the dashboard (Admin → Platform Policy),
 * stored in the `settings` table (scope=global, keys prefixed `policy_`) — no .env edit / redeploy.
 *
 *  - e-mail rules: block list (exact address / domain / `*` wildcard), allow list (always wins),
 *    and on/off switches for each built-in check (disposable domains, "+tag"/dotted-Gmail patterns, DNS/MX);
 *  - public registration on/off;
 *  - API response encryption on/off (read by UipResponseEncryptionMiddleware / SecureHandshakeController).
 *
 * Reads are cached for a few seconds (and flushed on every save), and every read falls back to the
 * safe default when the DB is unreachable, so a settings outage can never lock people out.
 *
 * Rule syntax (one per entry, case-insensitive):
 *   spam.com            -> type "domain":  spam.com and every sub-domain (x.spam.com)
 *   @spam.com           -> same as above
 *   bad@x.com           -> type "email":   that exact address
 *   bot*@*.xyz          -> type "pattern": `*` matches any run of characters
 */
final class PlatformPolicy
{
    public const PREFIX = 'policy_';
    public const LIST_BLOCK = 'block';
    public const LIST_ALLOW = 'allow';
    private const RULES_KEY = ['block' => 'policy_email_block_rules', 'allow' => 'policy_email_allow_rules'];
    private const CACHE_TTL = 15;
    public const MAX_RULES = 2000;

    /** Boolean switches the admin can flip: key => default. Defaults for e-mail checks follow config/mail.php. */
    public const FLAGS = [
        'email_block_enabled'    => true,  // custom block list active
        'disposable_check'       => true,  // built-in temp-mail provider list
        'plus_addressing_check'  => true,  // name+tag@... aliases
        'suspicious_gmail_check' => true,  // dotted / numeric Gmail temp addr
        'email_dns_check'        => true,  // domain must have MX / A record (sign-up only)
        'registration_enabled'   => true,  // public sign-up (form + Google)
        'response_encryption'    => true,  // AES-GCM envelope on JSON responses
        'response_enc_enforce'   => false, // clients without a session key are refused (default = RESPONSE_ENC_ENFORCE)
        'request_enc_enforce'    => false, // plain JSON request bodies are refused   (default = REQUEST_ENC_ENFORCE)
    ];

    /**
     * Typed values the admin can edit instead of touching .env. `default` is read from config (.env) when
     * the admin never saved one, and "reset" simply deletes the row so the .env value applies again.
     * Secrets (keys, DB, JWT, proxy secret…) are deliberately NOT here.
     */
    public const VALUES = [
        'platform_name'        => ['type' => 'string', 'min' => 1,  'max' => 60],   // APP_NAME  (e-mail header / footer)
        'session_live_seconds' => ['type' => 'int',    'min' => 30, 'max' => 1800], // SESSION_LIVE_SECONDS
        'google_client_ids'    => ['type' => 'string', 'min' => 0,  'max' => 700],  // GOOGLE_CLIENT_IDS (public IDs)
    ];

    /** @var array<string,string|null>|null */
    private static ?array $memo = null;
    private static int $memoAt = 0;

    // ───────────────────────── storage ─────────────────────────

    /** @return array<string,string|null> every `policy_*` row, cached briefly. Empty on any DB failure. */
    private static function all(): array
    {
        if (self::$memo !== null && (time() - self::$memoAt) < self::CACHE_TTL) {
            return self::$memo;
        }
        try {
            $rows = DB::table('settings')->where('scope', 'global')->whereNull('user_id')
                ->where('key', 'like', self::PREFIX . '%')->get(['key', 'value']);
            $out = [];
            foreach ($rows as $r) {
                $out[$r->key] = $r->value;
            }
        } catch (\Throwable $e) {
            // DB down / table missing: keep serving defaults for a few seconds instead of hammering it.
            $out = [];
        }
        self::$memo = $out;
        self::$memoAt = time();

        return $out;
    }

    public static function flush(): void
    {
        self::$memo = null;
        self::$memoAt = 0;
    }

    private static function put(string $key, string $value): void
    {
        app(SettingRepository::class)->set($key, $value, 'global', null);
        self::flush();
    }

    // ───────────────────────── switches ─────────────────────────

    /** Default of a switch when the admin never touched it (e-mail checks follow the .env/config values). */
    public static function defaultFor(string $flag): bool
    {
        return match ($flag) {
            'plus_addressing_check'  => (bool) config('mail.block_plus_addressing', true),
            'suspicious_gmail_check' => (bool) config('mail.block_suspicious_gmail', true),
            'response_enc_enforce'   => (bool) config('secure_transport.enforce', false),
            'request_enc_enforce'    => (bool) config('secure_transport.enforce_requests', false),
            default                  => self::FLAGS[$flag] ?? false,
        };
    }

    public static function flag(string $flag): bool
    {
        if (!array_key_exists($flag, self::FLAGS)) {
            return false;
        }
        $stored = self::all()[self::PREFIX . $flag] ?? null;

        return $stored === null ? self::defaultFor($flag) : $stored === '1';
    }

    /** @return array<string,bool> effective value of every switch */
    public static function flags(): array
    {
        $out = [];
        foreach (array_keys(self::FLAGS) as $k) {
            $out[$k] = self::flag($k);
        }

        return $out;
    }

    /** True when the switch was explicitly saved by an admin (vs. still on its default). */
    public static function isOverridden(string $flag): bool
    {
        return array_key_exists(self::PREFIX . $flag, self::all());
    }

    public static function setFlag(string $flag, bool $value): void
    {
        if (!array_key_exists($flag, self::FLAGS)) {
            throw new \InvalidArgumentException("Unknown policy switch: {$flag}");
        }
        self::put(self::PREFIX . $flag, $value ? '1' : '0');
    }

    /** Is the AES envelope active right now? (.env RESPONSE_ENC_ENABLED is the hard master switch.) */
    public static function encryptionOn(): bool
    {
        return (bool) config('secure_transport.enabled', true) && self::flag('response_encryption');
    }

    public static function registrationOpen(): bool
    {
        return self::flag('registration_enabled');
    }

    public static function registrationClosedMessage(string $locale): string
    {
        return $locale === 'ar'
            ? 'التسجيل الجديد مغلق حاليًا. تواصل مع إدارة المنصة.'
            : 'New registrations are currently closed. Please contact the platform administrators.';
    }

    /** Response enforcement actually applied (dashboard value, else .env). Only meaningful while encryption is on. */
    public static function responseEncEnforced(): bool
    {
        return self::flag('response_enc_enforce');
    }

    public static function requestEncEnforced(): bool
    {
        return self::flag('request_enc_enforce');
    }

    // ───────────────────────── editable values ─────────────────────────

    /** The .env / config value used while the admin has not saved one. */
    public static function defaultValue(string $key): string|int
    {
        return match ($key) {
            'platform_name'        => (string) config('app.name', 'UIP'),
            'session_live_seconds' => max(30, (int) config('security.single_session.live_seconds', 120)),
            'google_client_ids'    => (string) config('services.google.client_ids', ''),
            default                => '',
        };
    }

    public static function isValueOverridden(string $key): bool
    {
        return array_key_exists(self::PREFIX . $key, self::all());
    }

    /** Effective value: what the dashboard saved, otherwise the .env default. */
    public static function value(string $key): string|int
    {
        $def = self::VALUES[$key] ?? null;
        if ($def === null) {
            return '';
        }
        $stored = self::all()[self::PREFIX . $key] ?? null;
        if ($stored === null) {
            return self::defaultValue($key);
        }

        return $def['type'] === 'int' ? (int) $stored : (string) $stored;
    }

    /** @return array<string,string|int> */
    public static function values(): array
    {
        $out = [];
        foreach (array_keys(self::VALUES) as $k) {
            $out[$k] = self::value($k);
        }

        return $out;
    }

    /**
     * Validates what the admin typed. Returns [value, null] or [null, error message].
     * @return array{0:string|int|null,1:?string}
     */
    public static function normalizeValue(string $key, $raw): array
    {
        $def = self::VALUES[$key] ?? null;
        if ($def === null) {
            return [null, "Unknown setting: {$key}"];
        }
        if ($def['type'] === 'int') {
            if (!is_numeric($raw) || (string) (int) $raw !== trim((string) $raw)) {
                return [null, "{$key} must be a whole number."];
            }
            $n = (int) $raw;
            if ($n < $def['min'] || $n > $def['max']) {
                return [null, "{$key} must be between {$def['min']} and {$def['max']}."];
            }

            return [$n, null];
        }
        $v = trim((string) $raw);
        if (preg_match('/[\x00-\x1F\x7F<>]/u', $v)) {
            return [null, "{$key} contains characters that are not allowed."];
        }
        if (mb_strlen($v) < $def['min'] || mb_strlen($v) > $def['max']) {
            return [null, $def['min'] > 0
                ? "{$key} must be {$def['min']}–{$def['max']} characters."
                : "{$key} is too long (max {$def['max']})."];
        }
        if ($key === 'google_client_ids' && $v !== '') {
            $ids = array_values(array_filter(array_map('trim', explode(',', $v))));
            if (count($ids) > 5) {
                return [null, 'At most 5 Google client IDs.'];
            }
            foreach ($ids as $id) {
                if (!preg_match('/^[A-Za-z0-9._-]+\.apps\.googleusercontent\.com$/', $id)) {
                    return [null, "\"{$id}\" is not a valid Google client ID (…apps.googleusercontent.com)."];
                }
            }
            $v = implode(',', $ids);
        }

        return [$v, null];
    }

    public static function setValue(string $key, string|int $value): void
    {
        if (!isset(self::VALUES[$key])) {
            throw new \InvalidArgumentException("Unknown setting: {$key}");
        }
        self::put(self::PREFIX . $key, (string) $value);
    }

    /** Drops the dashboard value so the .env default applies again. */
    public static function resetValue(string $key): void
    {
        if (!isset(self::VALUES[$key])) {
            throw new \InvalidArgumentException("Unknown setting: {$key}");
        }
        DB::table('settings')->where('scope', 'global')->whereNull('user_id')->where('key', self::PREFIX . $key)->delete();
        self::flush();
    }

    // ───────────────────────── e-mail rules ─────────────────────────

    /**
     * Turns what the admin typed into a canonical rule, or null when it is not a usable rule.
     * @return array{0:string,1:string}|null [value, type]
     */
    public static function normalize($raw): ?array
    {
        $v = mb_strtolower(trim((string) $raw));
        $v = trim($v, " \t\n\r\0\x0B,;");
        if ($v === '' || mb_strlen($v) > 190 || preg_match('/[\s,;<>"\'\\\\\/:]/u', $v)) {
            return null;
        }
        if ($v[0] === '@') {
            $v = substr($v, 1);
        }
        if (substr_count($v, '@') > 1) {
            return null;
        }
        $hasWildcard = str_contains($v, '*');
        if (!str_contains($v, '@')) {
            // bare domain (optionally with wildcards such as *.xyz)
            if ($hasWildcard) {
                return self::meaningful($v) ? ['*@' . $v, 'pattern'] : null;
            }

            return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9-]{2,}$/', $v) === 1 ? [$v, 'domain'] : null;
        }
        [$local, $domain] = explode('@', $v, 2);
        if ($local === '' || $domain === '') {
            return null;
        }
        if ($hasWildcard) {
            return self::meaningful($v) ? [$v, 'pattern'] : null;
        }
        if (!preg_match('/^[a-z0-9.!#$%&\'*+\/=?^_`{|}~-]+$/', $local)
            || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9-]{2,}$/', $domain) !== 1) {
            return null;
        }

        return [$v, 'email'];
    }

    /** A wildcard rule must keep at least 2 literal characters (so `*@*.tk` is fine), so `*`, `*@*` or `*.*` can never block everybody. */
    private static function meaningful(string $v): bool
    {
        return mb_strlen(str_replace(['*', '@', '.'], '', $v)) >= 2;
    }

    /** @param array{value:string,type:string} $rule */
    public static function ruleMatches(array $rule, string $email): bool
    {
        $email = mb_strtolower(trim($email));
        $value = (string) ($rule['value'] ?? '');
        if ($email === '' || $value === '') {
            return false;
        }
        switch ($rule['type'] ?? '') {
            case 'email':
                return $email === $value;
            case 'domain':
                $domain = DisposableEmail::domainOf($email);

                return $domain !== '' && ($domain === $value || str_ends_with($domain, '.' . $value));
            case 'pattern':
                $re = '/^' . str_replace('\*', '.*', preg_quote($value, '/')) . '$/u';

                return preg_match($re, $email) === 1;
        }

        return false;
    }

    /** @return array<int,array{value:string,type:string,note:?string,added_by:?int,added_at:?string}> */
    public static function rules(string $list): array
    {
        $key = self::RULES_KEY[$list] ?? null;
        if ($key === null) {
            return [];
        }
        $decoded = json_decode((string) (self::all()[$key] ?? ''), true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $r) {
            if (is_array($r) && isset($r['value'], $r['type'])) {
                $out[] = [
                    'value'    => (string) $r['value'],
                    'type'     => (string) $r['type'],
                    'note'     => isset($r['note']) ? (string) $r['note'] : null,
                    'added_by' => isset($r['added_by']) ? (int) $r['added_by'] : null,
                    'added_at' => isset($r['added_at']) ? (string) $r['added_at'] : null,
                ];
            }
        }

        return $out;
    }

    /** First rule of the list that matches the address, or null. */
    public static function matchIn(string $list, $email): ?array
    {
        foreach (self::rules($list) as $rule) {
            if (self::ruleMatches($rule, (string) $email)) {
                return $rule;
            }
        }

        return null;
    }

    /** Allow list always wins over every other e-mail check. */
    public static function emailAllowed($email): bool
    {
        return self::matchIn(self::LIST_ALLOW, $email) !== null;
    }

    /** The custom block rule that refuses this address (null when the block list is off or nothing matches). */
    public static function emailBlockedBy($email): ?array
    {
        return self::flag('email_block_enabled') ? self::matchIn(self::LIST_BLOCK, $email) : null;
    }

    /**
     * Adds many rules at once. Invalid entries and duplicates are reported, not fatal.
     * @param iterable<string> $values
     * @return array{added:array<int,array>,invalid:array<int,string>,duplicates:array<int,string>,full:bool}
     */
    public static function addRules(string $list, iterable $values, ?string $note, ?int $adminId, array $protectEmails = []): array
    {
        $key = self::RULES_KEY[$list] ?? throw new \InvalidArgumentException('Unknown list');
        $current = self::rules($list);
        $have = array_column($current, 'value');
        $res = ['added' => [], 'invalid' => [], 'duplicates' => [], 'protected' => [], 'full' => false];
        $note = $note !== null ? mb_substr(trim($note), 0, 190) : null;

        foreach ($values as $raw) {
            if (trim((string) $raw) === '') {
                continue;
            }
            $norm = self::normalize($raw);
            if ($norm === null) {
                $res['invalid'][] = (string) $raw;
                continue;
            }
            [$value, $type] = $norm;
            if (in_array($value, $have, true)) {
                $res['duplicates'][] = $value;
                continue;
            }
            // Never let the admin block their own address (or the address they are protecting) by accident.
            if ($list === self::LIST_BLOCK) {
                foreach ($protectEmails as $mine) {
                    if (self::ruleMatches(['value' => $value, 'type' => $type], (string) $mine)) {
                        $res['protected'][] = $value;
                        continue 2;
                    }
                }
            }
            if (count($current) >= self::MAX_RULES) {
                $res['full'] = true;
                break;
            }
            $rule = ['value' => $value, 'type' => $type, 'note' => $note ?: null, 'added_by' => $adminId, 'added_at' => gmdate('c')];
            $current[] = $rule;
            $have[] = $value;
            $res['added'][] = $rule;
        }
        if ($res['added']) {
            self::put($key, json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $res;
    }

    /** @return bool true when something was removed */
    public static function removeRule(string $list, string $value): bool
    {
        $key = self::RULES_KEY[$list] ?? throw new \InvalidArgumentException('Unknown list');
        $value = mb_strtolower(trim($value));
        $current = self::rules($list);
        $kept = array_values(array_filter($current, fn ($r) => $r['value'] !== $value));
        if (count($kept) === count($current)) {
            return false;
        }
        self::put($key, json_encode($kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return true;
    }
}
