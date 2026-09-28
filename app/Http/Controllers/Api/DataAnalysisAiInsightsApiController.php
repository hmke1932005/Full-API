<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AIInsightsService;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * منقولة من app/Controllers/Api/DataAnalysisAiInsightsApiController.php
 * القديمة — بند 24 batch 5 (AI Insights، enhancement spec section 4).
 * سطح /api/v1/data-analysis/ai-insights واحد، بإعادة استخدام نفس نداءات
 * AIInsightsService::isAvailable()/latestCompleted()/latestAny()/
 * history()/generate() — محرك الـ AI analytics الحقيقي فوق مقاييس
 * المنصة الحية. نفس عقد "أبدًا مش مختلق": نداء AI غير مُعدّ أو فاشل
 * بيرجّع apiError حقيقي، أبدًا مش تقرير شكله مختلق.
 *
 * RBAC: uip.auth بيغطي الجروب؛ isDataAnalyst() بيتأكد كمان في كل ميثود
 * (data_analyst أو admin) — نفس قاعدة باقي كنترولرز DataAnalysis*.
 */
class DataAnalysisAiInsightsApiController extends Controller
{
    public function __construct(
        private AIInsightsService $insights,
        private AuditLogService $auditLog
    ) {
    }

    /** GET /api/v1/data-analysis/ai-insights */
    public function index(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can view AI Insights.', null, 403);
        }

        $latest = $this->insights->latestCompleted();
        $latestAny = $this->insights->latestAny();

        return $this->apiSuccess([
            'available'   => $this->insights->isAvailable(),
            'latest'      => $latest,
            'result'      => $latest ? $this->decodeResult($latest['result_json'] ?? null) : null,
            'last_failed' => ($latestAny && $latestAny['status'] === 'failed') ? $latestAny : null,
            'history'     => $this->insights->history(10),
        ], 'AI Insights retrieved successfully.');
    }

    /** POST /api/v1/data-analysis/ai-insights/regenerate */
    public function regenerate(Request $request): JsonResponse
    {
        if (!$this->isDataAnalyst($request)) {
            return $this->apiError('Only Data Analysis Portal accounts can regenerate AI Insights.', null, 403);
        }

        if (!$this->insights->isAvailable()) {
            return $this->apiError('AI provider is not configured yet — ask an admin to add an API key under Settings → AI.', null, 422);
        }

        $userId = $request->attributes->get('uip_user_id');

        try {
            $report = $this->insights->generate((int) $userId);
            $this->auditLog->record($userId, 'ai_insights.generate', 'ai_insight_report', $report->id, null, ['status' => 'completed']);

            return $this->apiSuccess([
                'result' => $this->decodeResult($report->result_json ?? null),
            ], 'AI Insights regenerated.');
        } catch (\Throwable $e) {
            $this->auditLog->record($userId, 'ai_insights.generate_failed', 'ai_insight_report', null, null, ['error' => $e->getMessage()]);
            return $this->apiError($e->getMessage(), null, 500);
        }
    }

    private function decodeResult(?string $json): ?array
    {
        if (!$json) {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function isDataAnalyst(Request $request): bool
    {
        return in_array($request->attributes->get('uip_role'), ['data_analyst', 'admin'], true);
    }
}
