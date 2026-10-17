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

    /** DELETE /api/v1/admin/mobile-tokens/{id} — permanently removes a token that was already revoked. */
    public function destroy(Request $request, string $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $ok = $this->tokens->deleteRevoked((int) $id);

        if ($ok) {
            $this->auditLog->record($request->attributes->get('uip_user_id'), 'api_token.delete', 'ApiToken', (int) $id);
            return $this->apiSuccess(null, 'Token deleted.');
        }

        return $this->apiError('Token not found, or it is still active — revoke it first.', null, 404);
    }

    /** GET /api/v1/admin/mobile-tokens/{id} — token info + full usage summary (users / IPs / devices / endpoints / daily trend). */
    public function show(Request $request, string $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        $token = $this->tokens->findWithCreator((int) $id);
        if (!$token) {
            return $this->apiError('Token not found.', null, 404);
        }

        return $this->apiSuccess([
            'token'   => $token,
            'summary' => $this->tokens->usageSummary((int) $id),
        ], 'Token details retrieved successfully.');
    }

    /** GET /api/v1/admin/mobile-tokens/{id}/requests?page=&per_page=&status=ok|error|rejected&status_code=&endpoint=&method=&user_id=&ip=&q= */
    public function requests(Request $request, string $id)
    {
        if ($err = $this->requireAdmin($request)) {
            return $err;
        }

        if (!$this->tokens->findWithCreator((int) $id)) {
            return $this->apiError('Token not found.', null, 404);
        }

        $page = max(1, (int) $request->query('page', 1));
        $per = min(100, max(10, (int) $request->query('per_page', 50)));
        $res = $this->tokens->usageLog((int) $id, $page, $per, [
            'status'  => (string) $request->query('status', ''),
            'status_code' => (int) $request->query('status_code', 0),
            'endpoint'    => (string) $request->query('endpoint', ''),
            'method'      => (string) $request->query('method', ''),
            'user_id' => $request->query('user_id'),
            'ip'      => (string) $request->query('ip', ''),
            'q'       => (string) $request->query('q', ''),
        ]);

        return $this->apiSuccess($res['rows'], 'Token requests retrieved successfully.', 200, [
            'page' => $page, 'per_page' => $per, 'total' => $res['total'], 'pages' => (int) max(1, ceil($res['total'] / $per)),
        ]);
    }
}
