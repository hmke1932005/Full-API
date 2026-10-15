<?php

namespace App\Support;

use App\Services\UipJwtService;
use Illuminate\Support\Facades\Cache;

/**
 * Server-side access-code gate for the hidden staff sign-in (see config/staff_gate.php).
 * The code is checked here only; the browser just holds a short-lived signed ticket.
 */
final class StaffGate
{
    private const TYP = 'staff_gate';

    public static function enabled(): bool
    {
        return (bool) config('staff_gate.enabled', true);
    }

    /** @return array<string,string> code => group (invalid pairs and unknown groups ignored) */
    private static function codes(): array
    {
        $groups = (array) config('staff_gate.groups', []);
        $out = [];
        foreach (explode(',', (string) config('staff_gate.codes', '')) as $pair) {
            [$code, $group] = array_pad(explode(':', trim($pair), 2), 2, '');
            $code = trim($code);
            $group = trim($group);
            if ($code !== '' && isset($groups[$group])) {
                $out[$code] = $group;
            }
        }

        return $out;
    }

    /** Group of the code, or null. Compares against every code so timing does not reveal a near-miss. */
    public static function groupFor(string $code): ?string
    {
        $found = null;
        foreach (self::codes() as $known => $group) {
            if (hash_equals((string) $known, $code)) {
                $found = $group;
            }
        }

        return $found;
    }

    // ───────── brute-force protection ─────────

    /** Seconds the caller must still wait (0 = free to try). */
    public static function retryAfter(string $ip): int
    {
        $until = (int) Cache::get('staff_gate:lock:' . sha1($ip), 0);
        $wait = max(0, $until - time());
        if ($wait > 0) {
            return $wait;
        }
        $globalUntil = (int) Cache::get('staff_gate:global_lock', 0);

        return max(0, $globalUntil - time());
    }

    /** Records a wrong code; returns attempts left before this IP is locked (0 when it just got locked). */
    public static function registerFailure(string $ip): int
    {
        $max = max(1, (int) config('staff_gate.max_attempts', 5));
        $key = 'staff_gate:fail:' . sha1($ip);
        $n = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $n, 3600);

        if ($n >= $max) {
            $strikesKey = 'staff_gate:strikes:' . sha1($ip);
            $strikes = (int) Cache::get($strikesKey, 0) + 1;
            Cache::put($strikesKey, $strikes, 86400);
            $minutes = min(1440, max(1, (int) config('staff_gate.lock_minutes', 15)) * (2 ** ($strikes - 1)));
            Cache::put('staff_gate:lock:' . sha1($ip), time() + $minutes * 60, $minutes * 60);
            Cache::forget($key);
        }

        // Distributed guessing: too many wrong codes overall in a short window -> everybody waits a bit.
        $gKey = 'staff_gate:global_fail';
        $g = (int) Cache::get($gKey, 0) + 1;
        Cache::put($gKey, $g, 600);
        if ($g >= max(10, (int) config('staff_gate.global_limit', 100))) {
            Cache::put('staff_gate:global_lock', time() + 600, 600);
            Cache::forget($gKey);
        }

        return max(0, $max - $n);
    }

    public static function clearFailures(string $ip): void
    {
        Cache::forget('staff_gate:fail:' . sha1($ip));
    }

    // ───────── ticket ─────────

    public static function issue(string $group): string
    {
        return UipJwtService::encode(['typ' => self::TYP, 'grp' => $group], (int) config('staff_gate.ttl', 1800));
    }

    /** @return string[]|null staff roles the ticket opens, null when the ticket is missing / forged / expired */
    public static function rolesFromTicket($ticket): ?array
    {
        if (!is_string($ticket) || $ticket === '' || strlen($ticket) > 4096) {
            return null;
        }
        $claims = UipJwtService::decode($ticket);
        if (!$claims || ($claims['typ'] ?? null) !== self::TYP || !isset($claims['grp'])) {
            return null;
        }
        $roles = config('staff_gate.groups.' . $claims['grp']);

        return is_array($roles) ? array_values($roles) : null;
    }
}
