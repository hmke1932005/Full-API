<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ResponseCryptoService;
use Illuminate\Http\Request;

/** POST /api/v1/secure/handshake { pub } -> { kid, server_pub, salt }  (ephemeral ECDH P-256). */
class SecureHandshakeController extends Controller
{
    public function submit(Request $request, ResponseCryptoService $crypto)
    {
        $pub = (string) $request->input('pub', '');
        if ($pub === '' || strlen($pub) > 200) {
            return $this->apiError('Invalid key.', null, 422);
        }
        $res = $crypto->handshake($pub);
        if (!$res) {
            return $this->apiError('Invalid key.', null, 422);
        }

        return $this->apiSuccess($res, 'Secure channel ready.')->header('Cache-Control', 'no-store');
    }
}
