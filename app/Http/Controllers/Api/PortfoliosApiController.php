<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PortfolioService;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/PortfoliosApiController.php القديمة —
 * بند 12 (Portfolios). سيرفر REST واحد /api/v1/portfolios/* للصفحة
 * القابلة للمشاركة (`portfolios` + `portfolio_projects`، migration 016)،
 * بتستخدم PortfolioService/PortfolioRepository بالظبط زي ما
 * StudentPortfolio.jsx بيعمل. PortfolioService نفسها مش role-gated —
 * "الصفحة العامة/القابلة للمشاركة اللي الطلاب، الباحثين... بيستخدموها
 * عشان يعرضوا مجموعة مختارة من مشاريعهم المنشورة" زي ما docblock موديل
 * Portfolio نفسه بيقول — فالكنترولر هنا كمان مش مقصور على role واحد؛ أي
 * مستخدم مسجّل عنده مشاريع منشورة يقدر يبقى عنده portfolio.
 *
 * show() بتطابق سلوك PublicPortfolioController القديمة تمامًا (/p/{uuid}:
 * 404 لـ uuid مش موجود OU portfolio صاحبه خلاه private، عشان الاستجابة
 * أبدًا متسربّش أنهي uuid موجود) لكن فاضلة جوه الجروب العادي المتحكم فيه
 * بـ uip.auth، زي كل موديول تاني في الـ REST layer ده (مثلاً
 * ProjectsApiController::discover() كمان endpoint "تصفح" auth-only هنا،
 * رغم إن نظيرتها في الـ web عامة).
 *
 * RBAC: uip.auth بتغطي الجروب كله. كل فعل تعديل بيشتغل بس على الـ
 * portfolio بتاع الكولر نفسه (uip_user_id متحلّل من الـ middleware، زي
 * أي استدعاء تاني لـ PortfolioService) — مفيش تعديل عبر مستخدمين.
 */
class PortfoliosApiController extends Controller
{
    public function __construct(private PortfolioService $portfolios)
    {
    }

    // -- List (portfolio الكولر نفسه: البروفايل + المؤهلة + المميزة) -------

    /** GET /api/v1/portfolios/me */
    public function me(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $data = $this->portfolios->forOwner($userId);
        $user = User::find($userId);

        return $this->apiSuccess([
            'portfolio'         => $data['portfolio']->toArray(),
            'eligible_projects' => $data['eligibleProjects'],
            'featured_projects' => $data['featuredProjects'],
            'share_url'         => $this->publicBaseUrl() . '/p/' . ($user->uuid ?? ''),
        ], 'Portfolio retrieved successfully.');
    }

    /**
     * أساس رابط الفرونت (SPA) عشان بناء share_url — config('app.url') هنا
     * عنوان الباك إند نفسه (Laravel API)، مش الفرونت الـ React اللي شغال
     * على بورت/دومين تاني في التطوير (Vite على :5173 مثلًا). نفس منطق
     * MailService::loginUrl()/UniversitiesApiController::publicBaseUrl()
     * بالظبط: FRONTEND_URL لو متظبطة في .env، وإلا رجوع لـ APP_URL.
     */
    private function publicBaseUrl(): string
    {
        return rtrim((string) (config('app.frontend_url') ?: config('app.url', 'http://localhost')), '/');
    }

    // -- Show (عرض بشكل عام بـ uuid مستخدم تاني) -----------------------------

    /** GET /api/v1/portfolios/{uuid} */
    public function show(Request $request, string $uuid)
    {
        $user = User::where('uuid', $uuid)->first();
        if (!$user) {
            return $this->apiError('Portfolio not found.', null, 404);
        }

        $data = $this->portfolios->forPublicShare($user->id);
        if (!$data) {
            return $this->apiError('Portfolio not found.', null, 404);
        }

        return $this->apiSuccess([
            'owner'             => ['full_name' => $user->full_name, 'uuid' => $user->uuid],
            'portfolio'         => $data['portfolio']->toArray(),
            'featured_projects' => $data['featuredProjects'],
        ], 'Portfolio retrieved successfully.');
    }

    // -- Update (headline/about/theme/visibility) ----------------------------

    /** PATCH /api/v1/portfolios/me — headline/about/is_public عبر PortfolioService (نفس المنطق القديم)؛ theme عبر fill+save مباشر (نفس fillable field، مفيش service method ليها). */
    public function update(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $portfolio = $this->portfolios->forOwner($userId)['portfolio'];

        $this->portfolios->updateProfile($userId, [
            'headline'  => $request->has('headline') ? $request->input('headline') : $portfolio->headline,
            'about'     => $request->has('about') ? $request->input('about') : $portfolio->about,
            'is_public' => $request->has('is_public') ? $request->boolean('is_public') : (bool) $portfolio->is_public,
        ]);

        if ($request->has('theme')) {
            $portfolio->fill(['theme' => $request->input('theme') ?: null]);
            $portfolio->save();
        }

        return $this->apiSuccess(
            $this->portfolios->forOwner($userId)['portfolio']->toArray(),
            'Portfolio updated.'
        );
    }

    // -- Publish / Unpublish (تبديل الظهور) -----------------------------------

    /** POST /api/v1/portfolios/publish — يخلي الـ portfolio ظاهر عام على /p/{uuid}. */
    public function publish(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $this->portfolios->updateProfile($userId, $this->currentProfile($userId, ['is_public' => true]));
        return $this->apiSuccess(null, 'Portfolio published.');
    }

    /** POST /api/v1/portfolios/unpublish — بيخفي الـ portfolio عن رابط المشاركة العام. */
    public function unpublish(Request $request)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $this->portfolios->updateProfile($userId, $this->currentProfile($userId, ['is_public' => false]));
        return $this->apiSuccess(null, 'Portfolio unpublished.');
    }

    // -- المشاريع المميزة (أنهي مشاريع منشورة تظهر على الـ portfolio) --------

    /** POST /api/v1/portfolios/projects/{projectId}/feature — بتبدّل حالة "مميز" لمشروع منشور بتاع الكولر نفسه. */
    public function toggleFeature(Request $request, int $projectId)
    {
        $userId = (int) $request->attributes->get('uip_user_id');
        $this->portfolios->toggleFeatured($userId, $projectId);

        return $this->apiSuccess(
            $this->portfolios->forOwner($userId)['featuredProjects'],
            'Featured projects updated.'
        );
    }

    // -- helpers --------------------------------------------------------------

    /** @return array{headline:?string, about:?string, is_public:bool} حقول البروفايل الحالية + $overrides — لـ PATCH بحقل واحد عبر توقيع PortfolioService::updateProfile() اللي بياخد الـ payload كامل. */
    private function currentProfile(int $userId, array $overrides): array
    {
        $portfolio = $this->portfolios->forOwner($userId)['portfolio'];
        return array_merge([
            'headline'  => $portfolio->headline,
            'about'     => $portfolio->about,
            'is_public' => (bool) $portfolio->is_public,
        ], $overrides);
    }
}
