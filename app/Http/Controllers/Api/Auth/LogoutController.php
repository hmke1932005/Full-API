<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\RefreshToken;
use App\Support\SecurityLog;
use Illuminate\Http\Request;

/** يطابق LogoutController::handle() القديم بالظبط (شكل رد بسيط، مش envelope كامل). */
class LogoutController extends Controller
{
    public function handle(Request $request)
    {
        $refreshToken = (string) $request->input('refresh_token', '');
        if ($refreshToken !== '') {
            $row = RefreshToken::where('token_hash', hash('sha256', $refreshToken))
                ->whereNull('revoked_at')
                ->first();
            if ($row) {
                $row->update(['revoked_at' => now()]);
                SecurityLog::write('User logged out', ['user_id' => $row->user_id]);
            }
        }

        return response()->json(['success' => true, 'redirect' => '/auth/login']);
    }
}
