<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Per-client response encryption key, negotiated with ephemeral ECDH (P-256) and
 * HKDF-SHA256 -> AES-256-GCM. Every browser tab/page load gets a fresh key, so
 * there is no static key shipped in the JS bundle and past traffic stays safe if
 * a later key leaks (forward secrecy for this layer). The key only lives in the
 * server cache for `key_ttl` seconds.
 */
class ResponseCryptoService
{
    private const SPKI_P256_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function b64uEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    /** @return array{kid:string,server_pub:string,salt:string}|null */
    public function handshake(string $clientPubB64u): ?array
    {
        $point = self::b64uDecode($clientPubB64u);
        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            return null;
        }

        $der = hex2bin(self::SPKI_P256_PREFIX) . $point;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $clientKey = @openssl_pkey_get_public($pem);
        if (!$clientKey) {
            return null; // not a valid curve point
        }

        $server = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$server) {
            return null;
        }
        $ec = openssl_pkey_get_details($server)['ec'] ?? null;
        if (!$ec) {
            return null;
        }
        $serverPub = "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);

        $shared = openssl_pkey_derive($server, $clientKey, 32);
        if ($shared === false) {
            return null;
        }

        $salt = random_bytes(32);
        $kid  = bin2hex(random_bytes(16));
        // Two independent keys: server->client (responses) and client->server (requests).
        $keys = [
            'resp' => hash_hkdf('sha256', $shared, 32, 'uip-resp-enc-v1', $salt),
            'req'  => hash_hkdf('sha256', $shared, 32, 'uip-req-enc-v1', $salt),
        ];

        Cache::put('resp_enc:' . $kid, json_encode(array_map('base64_encode', $keys)), (int) config('secure_transport.key_ttl', 7200));

        return ['kid' => $kid, 'server_pub' => self::b64uEncode($serverPub), 'salt' => self::b64uEncode($salt)];
    }

    /** @return array{resp:string,req:string}|null */
    public function keysFor(string $kid): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $kid)) {
            return null;
        }
        $v = Cache::get('resp_enc:' . $kid);
        $arr = $v ? json_decode($v, true) : null;
        if (!is_array($arr) || !isset($arr['resp'], $arr['req'])) {
            return null;
        }

        return ['resp' => base64_decode($arr['resp']), 'req' => base64_decode($arr['req'])];
    }

    /**
     * Decrypts a client->server envelope body. AAD binds it to key id + method + path, and the
     * plaintext carries a timestamp + nonce so a captured request cannot be replayed.
     * @return array|null decoded JSON body, or null when invalid/stale/replayed
     */
    public function decryptRequest(string $d, string $key, string $kid, string $method, string $path): ?array
    {
        $raw = self::b64uDecode($d);
        if (strlen($raw) < 12 + 16 + 1) {
            return null;
        }
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, -16);
        $ct  = substr($raw, 12, -16);

        $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, "uip-req|$kid|" . strtoupper($method) . '|' . $path);
        if ($plain === false) {
            return null;
        }
        $wrap = json_decode($plain, true);
        if (!is_array($wrap) || !isset($wrap['t'], $wrap['n'])) {
            return null;
        }
        if (abs((int) (microtime(true) * 1000) - (int) $wrap['t']) > 120000) {
            return null; // stale
        }
        if (!Cache::add('req_nonce:' . $kid . ':' . substr((string) $wrap['n'], 0, 40), 1, 300)) {
            return null; // replay
        }

        $body = json_decode((string) ($wrap['b'] ?? ''), true);

        return is_array($body) ? $body : [];
    }

    /** AES-256-GCM; AAD binds the ciphertext to this key id and HTTP status. Output = base64url(iv|ct|tag). */
    public function encrypt(string $plain, string $key, string $kid, int $status): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, "uip-resp|$kid|$status", 16);
        if ($ct === false) {
            throw new \RuntimeException('Response encryption failed.');
        }

        return self::b64uEncode($iv . $ct . $tag);
    }
}
