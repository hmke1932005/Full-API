<?php

namespace App\Repositories;

use App\Models\ApiToken;
use Illuminate\Support\Facades\DB;

/**
 * منقولة من app/Repositories/ApiTokenRepository.php القديمة — بند 25
 * batch 4 (AdminMobileApiController). إدارة الـ bearer tokens طويلة
 * العمر الصادرة للعميل الموبايل المستقبلي.
 */
class ApiTokenRepository
{
    /** كل التوكنز (فعالة + ملغاة) مع اسم الأدمن اللي أصدرها، الأحدث الأول. */
    public function allWithCreator(): array
    {
        return DB::table('api_tokens as t')
            ->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->select('t.id', 't.name', 't.last_used_at', 't.revoked_at', 't.created_at', 'u.full_name as created_by_name')
            ->orderByDesc('t.created_at')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    public function create(array $data): ApiToken
    {
        return ApiToken::create($data);
    }

    public function revoke($id): bool
    {
        $token = ApiToken::find($id);
        if (!$token || $token->revoked_at) {
            return false;
        }
        $token->fill(['revoked_at' => now()]);
        $token->save();
        return true;
    }

    public function activeCount(): int
    {
        return ApiToken::whereNull('revoked_at')->count();
    }

    /** بتدور على توكن فعال بالـ hash بتاع الـ raw value — لميدلوير مستقبلية للـ API auth. */
    public function findActiveByRawToken(string $rawToken): ?ApiToken
    {
        return ApiToken::where('token_hash', hash('sha256', $rawToken))->whereNull('revoked_at')->first();
    }
}
