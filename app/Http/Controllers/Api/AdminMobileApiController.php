<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\ApiTokenRepository;
use App\Services\AuditLogService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/AdminMobileApiController.php القديمة —
 * بند 25 batch 4. سطح /api/v1/admin/mobile-tokens JSON واحد، بيعيد
 * استخدام ApiTokenRepository::allWithCreator()/create()/revoke()/
 * activeCount() بالظبط زي ما Admin\AdminMobileController القديمة كانت
 * بتعمل. "Mobile App Management" هو فعليًا إدارة الـ API bearer tokens
 * طويلة العمر الصادرة للعميل الموبايل المستقبلي. نفس نمط "one-time
 * reveal" بتاع القديمة: الـ raw token بيترجع في response إنشاء الـ
 * token فقط، ومش بيتخزن غير الـ SHA-256 hash بتاعه. مفيش حاجة مختلَقة هنا.
 */
class AdminMobileApiController extends Controller
{
    public function __construct(private ApiTokenRepository $tokens, private AuditLogService $auditLog)
    {
    }

    private function requireAdmin(Request $request)
    {
        if ($request->attributes->get('uip_role') !== 'admin') {
            return $this->apiError('Only admin accounts can manage mobile app tokens.', null, 403);
        }
        return null;
    }

    /** GET /api/v1/admin/mobile-tokens */
    public function index(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        return $this->apiSuccess($this->tokens->allWithCreator(), 'Mobile app tokens retrieved successfully.', 200, [
            'activeCount' => $this->tokens->activeCount(),
        ]);
    }

    /** POST /api/v1/admin/mobile-tokens — {name}. Returns the raw token once, in the response body. */
    public function store(Request $request)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return $this->apiError('Give this token a name (e.g. which app build it is for).', null, 422);
        }

        $raw = bin2hex(random_bytes(32));
        $adminId = $request->attributes->get('uip_user_id');

        $token = $this->tokens->create([
            'name'       => $name,
            'token_hash' => hash('sha256', $raw),
            'created_by' => $adminId,
        ]);

        $this->auditLog->record($adminId, 'api_token.create', 'ApiToken', $token->id, null, ['name' => $name]);

        return $this->apiSuccess([
            'id'           => $token->id,
            'name'         => $name,
            'issued_token' => $raw, // shown once — never recoverable after this response
        ], 'Token created. Copy it now — it will not be shown again.', 201);
    }

    /** POST /api/v1/admin/mobile-tokens/{id}/revoke */
    public function revoke(Request $request, string $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $ok = $this->tokens->revoke((int) $id);

        if ($ok) {
            $this->auditLog->record($request->attributes->get('uip_user_id'), 'api_token.revoke', 'ApiToken', (int) $id);
        }

        return $ok
            ? $this->apiSuccess(null, 'Token revoked.')
            : $this->apiError('Token not found or already revoked.', null, 404);
    }
}
