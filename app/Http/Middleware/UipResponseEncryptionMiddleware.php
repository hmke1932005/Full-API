<?php

namespace App\Http\Middleware;

use App\Services\ResponseCryptoService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Encrypts every JSON API response body for clients that negotiated a key
 * (header X-Enc-Kid, see SecureHandshakeController). Files, streams and empty
 * bodies are left alone. With RESPONSE_ENC_ENFORCE=true a request without a
 * valid key is refused before it reaches any controller.
 */
class UipResponseEncryptionMiddleware
{
    public function __construct(private ResponseCryptoService $crypto)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        if (!config('secure_transport.enabled') || $request->isMethod('OPTIONS')) {
            return $next($request);
        }
        foreach ((array) config('secure_transport.except', []) as $path) {
            if ($request->is($path)) {
                return $next($request);
            }
        }

        $kid  = (string) $request->header('X-Enc-Kid', '');
        $keys = $kid !== '' ? $this->crypto->keysFor($kid) : null;
        $key  = $keys['resp'] ?? null;

        if ($key === null) {
            if ($kid !== '' || config('secure_transport.enforce')) {
                // Rejected before any controller ran, so the client can safely retry after a new handshake.
                return response()->json([
                    'success' => false,
                    'message' => 'Secure channel required.',
                    'data'    => null,
                    'errors'  => ['code' => $kid !== '' ? 'enc_key_expired' : 'enc_required'],
                    'meta'    => (object) [],
                ], 428)->header('Cache-Control', 'no-store');
            }
            return $next($request);
        }

        // Encrypted request body (JSON envelope) -> decrypt and expose it as the normal JSON input.
        if (!in_array($request->method(), ['GET', 'HEAD'], true) && $request->isJson()) {
            $raw = (string) $request->getContent();
            $env = $raw !== '' ? json_decode($raw, true) : null;
            if (is_array($env) && ($env['enc'] ?? null) === 'v1' && isset($env['d']) && ($env['kid'] ?? '') === $kid) {
                $body = $this->crypto->decryptRequest((string) $env['d'], $keys['req'], $kid, $request->method(), '/' . ltrim($request->path(), '/'));
                if ($body === null) {
                    return response()->json([
                        'success' => false, 'message' => 'Invalid or expired secure request.', 'data' => null,
                        'errors' => ['code' => 'enc_bad_request'], 'meta' => (object) [],
                    ], 400)->header('Cache-Control', 'no-store');
                }
                $request->setJson(new \Symfony\Component\HttpFoundation\InputBag($body));
                $request->attributes->set('uip_enc_request', true);
            } elseif ($raw !== '' && config('secure_transport.enforce_requests')) {
                return response()->json([
                    'success' => false, 'message' => 'Encrypted request body required.', 'data' => null,
                    'errors' => ['code' => 'enc_required'], 'meta' => (object) [],
                ], 428)->header('Cache-Control', 'no-store');
            }
        }

        $response = $next($request);

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse
            || in_array($response->getStatusCode(), [204, 205, 304], true)
            || !str_contains((string) $response->headers->get('Content-Type', ''), 'json')) {
            return $response;
        }

        $status   = $response->getStatusCode();
        $envelope = json_encode(['enc' => 'v1', 'kid' => $kid, 'd' => $this->crypto->encrypt((string) $response->getContent(), $key, $kid, $status)]);

        $response->setContent($envelope);
        $response->headers->set('X-Enc', '1');
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->remove('Content-Length');
        $response->headers->remove('ETag');

        return $response;
    }
}
