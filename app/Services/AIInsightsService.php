<?php

namespace App\Services;

use App\Models\AIInsightReport;
use App\Repositories\AIInsightRepository;
use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;
use App\Services\Concerns\ConfiguresAIClient;

/**
 * منقولة من app/Services/AIInsightsService.php القديمة — بند 24 batch 5
 * (AI Insights، enhancement spec section 4). محرك الـ AI analytics
 * الحقيقي: بيبني digest مختصر لمقاييس المنصة الحية (AnalyticsService +
 * KpiService + DataQualityService — نفس الأرقام المحسوبة اللي ظاهرة
 * فعليًا في Analytics Dashboard/KPI Management/Data Quality Center)
 * وبيبعتها لـ App\Services\Ai\AIClient طالبًا تقرير JSON منظّم يغطي كل
 * قسم مذكور في الـ spec: insights, trends, correlations, anomalies,
 * recommendations, root cause analysis, executive summary, kpi
 * analysis, risk detection, opportunity detection, performance
 * recommendations.
 *
 * نفس عقد "أبدًا مش مختلق": لو الـ AI client مش مُعدّ أو النداء/الفك
 * فشل، بترمي — الكنترولر بيسجل صف history بحالة 'failed' (شوف
 * AIInsightRepository) وبيعرض خطأ حقيقي، أبدًا مش تقرير شكله مختلق.
 * تشغيلة ناجحة بتتسجل كصف 'completed' جديد (الـ history append-only،
 * فـ"regenerate كل ما البيانات تتغير" أبدًا مابيمسحش التقرير السابق).
 */
class AIInsightsService
{
    use ConfiguresAIClient;

    /** كل مفتاح متوقّع من الواجهة — رد completeJson() بيتطبّع عليه عشان رد جزئي من الـ AI أبدًا مايكسرش العرض. */
    private const SCHEMA_KEYS = [
        'executive_summary', 'insights', 'trends', 'correlations', 'anomalies',
        'recommendations', 'root_cause_analysis', 'kpi_analysis', 'risks',
        'opportunities', 'performance_recommendations',
    ];

    private AIClient $client;

    public function __construct(
        AIClient $client,
        SettingRepository $settings,
        private AIInsightRepository $repo,
        private AnalyticsService $analytics,
        private KpiService $kpis,
        private DataQualityService $quality,
        private DataAnalysisAcademicService $academic
    ) {
        $this->client = $client;
        $this->applySettingsOverrides($this->client, $settings);
    }

    public function isAvailable(): bool
    {
        return $this->client->isConfigured();
    }

    public function latestCompleted(): ?array
    {
        return $this->repo->latestCompleted();
    }

    public function latestAny(): ?array
    {
        return $this->repo->latestAny();
    }

    /** @return array<int,array<string,mixed>> */
    public function history(int $limit = 10): array
    {
        return $this->repo->history($limit);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->repo->find($id);
    }

    /** @param int[] $ids @return int عدد الصفوف اللي اتمسحت */
    public function deleteByIds(array $ids): int
    {
        return $this->repo->deleteByIds($ids);
    }

    /** @return int عدد الصفوف اللي اتمسحت */
    public function deleteAll(): int
    {
        return $this->repo->deleteAll();
    }

    /**
     * بتشغّل التحليل فوق digest بيانات حقيقية طازة وبتخزّن النتيجة. دايمًا
     * بتكتب صف history، حتى لو فشلت.
     * @throws \RuntimeException لو مش مُعدّ أو نداء/فك الـ AI فشل (بعد تسجيل الفشل)
     */
    public function generate(int $userId): AIInsightReport
    {
        $snapshot = $this->buildSnapshot();

        try {
            // ملحوظة حالة: البرومبت تحت بيحدد حد أقصى "up to N items" لكل
            // مصفوفة وبيطلب سطر واحد لكل عنصر. ده تحكّم متعمّد في الـ latency،
            // مش تقليل جودة — النسخة الأولى كانت بتطلب تقرير مفتوح، وكان
            // بيتخطى AI_TIMEOUT (config/ai.php) بانتظام على المزوّدين
            // البطيئين/اللي بيفكروا. رد محدود أسرع في التوليد والبث،
            // فوق رفع AI_TIMEOUT لهامش أمان.
            $language = app()->getLocale() === 'ar'
                ? 'Write the ENTIRE report in Modern Standard Arabic (Fusha) — every string value in the JSON (executive_summary, insights, descriptions, issue/likely_cause, recommendations, everything) must be Arabic prose. Keep JSON keys, and the fixed enum values ("up"/"down"/"flat", "strong"/"moderate"/"weak", "low"/"medium"/"high"), in English exactly as specified — only the human-readable text you write goes in Arabic. Numbers, category names, KPI names, and university names should be copied from the digest as given, not translated.'
                : 'Write the entire report in English.';

            $system = 'You are a senior business intelligence analyst for a university innovation & startup platform. '
                . 'You will be given a JSON digest of real, live platform metrics (user growth, category/project growth, '
                . 'university leaderboard, KPIs with targets/achievement/trend, exam-platform metrics under "exams_and_academic" '
                . '(exam results, pass rate, score bands, attempt status, at-risk student counts, integrity/appeals, doctor activity), '
                . 'and a data quality overview). '
                . 'Analyze it honestly and specifically — reference the actual numbers, categories, KPI names, and '
                . 'universities given to you, never generic filler. Be concise: one sentence per item, and cap every '
                . 'array at the maximum size stated below so the report stays fast to generate. '
                . $language . ' '
                . 'Reply with ONLY a single JSON object with exactly these keys: '
                . '"executive_summary" (string, 2-3 sentences), '
                . '"insights" (array, up to 5 short strings), '
                . '"trends" (array, up to 4 of {"label":string,"description":string,"direction":"up"|"down"|"flat"}), '
                . '"correlations" (array, up to 3 of {"description":string,"strength":"strong"|"moderate"|"weak"}), '
                . '"anomalies" (array, up to 3 of {"description":string,"severity":"low"|"medium"|"high"}), '
                . '"recommendations" (array, up to 4 short actionable strings), '
                . '"root_cause_analysis" (array, up to 3 of {"issue":string,"likely_cause":string}), '
                . '"kpi_analysis" (array, one entry per KPI given, of {"kpi":string,"analysis":string}), '
                . '"risks" (array, up to 3 of {"description":string,"severity":"low"|"medium"|"high"}), '
                . '"opportunities" (array, up to 3 of {"description":string,"potential_impact":"low"|"medium"|"high"}), '
                . '"performance_recommendations" (array, up to 4 short actionable strings). '
                . 'If a metrics category has no data, return an empty array for it rather than inventing content.';

            $user = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            $raw = $this->completeJson($this->client, $system, $user);
            $result = $this->normalize($raw);
            $result['language'] = app()->getLocale() === 'ar' ? 'ar' : 'en'; // لغة التقرير، عشان الواجهة تعرف لو مختلفة عن لغة العرض

            return $this->repo->record([
                'status'             => 'completed',
                'result_json'        => json_encode($result, JSON_UNESCAPED_UNICODE),
                'data_snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                'model_used'         => 'configured-provider',
                'generated_by'       => $userId,
            ]);
        } catch (\Throwable $e) {
            $this->repo->record([
                'status'             => 'failed',
                'result_json'        => null,
                'data_snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                'error_message'      => mb_substr($e->getMessage(), 0, 500),
                'model_used'         => null,
                'generated_by'       => $userId,
            ]);
            throw new \RuntimeException('AI Insights generation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** بيتأكد كل مفتاح schema موجود بالشكل الصح، عشان الواجهة أبدًا ماتخمنش. */
    private function normalize(array $raw): array
    {
        $out = [];
        foreach (self::SCHEMA_KEYS as $key) {
            if ($key === 'executive_summary') {
                $out[$key] = is_string($raw[$key] ?? null) ? trim($raw[$key]) : '';
                continue;
            }
            $out[$key] = is_array($raw[$key] ?? null) ? array_values($raw[$key]) : [];
        }
        return $out;
    }

    /** digest حقيقي وحي — الحاجة الوحيدة المسموح للموديل يفكر فيها. */
    private function buildSnapshot(): array
    {
        $overview = $this->analytics->platformOverview();
        $userGrowth = $this->analytics->userGrowthSeries(6);
        $categoryDistribution = $this->analytics->categoryDistribution(8);
        $categoryGrowth = $this->analytics->categoryGrowthTrends(30, 8);
        $universities = $this->analytics->universityLeaderboard(5);

        $kpis = array_map(fn ($k) => [
            'name'            => $k['name'],
            'category'        => $k['category'],
            'unit'            => $k['unit'],
            'current_value'   => (float) $k['current_value'],
            'target_value'    => (float) $k['target_value'],
            'direction'       => $k['direction'],
            'trend'           => $k['trend'],
            'growth_rate_pct' => $k['growth_rate'],
            'achievement_pct' => $k['achievement_pct'],
            'alert_triggered' => $k['alert_triggered'],
        ], array_slice($this->kpis->list('active'), 0, 12)); // محدودة: بتخلي حجم البرومبت/الرد (وبالتالي الـ latency) محدود على المنصات اللي فيها KPIs كتير

        $qualityReports = $this->quality->overview();
        $qualitySummary = array_map(fn ($r) => [
            'dataset'      => $r['label']['en'] ?? $r['key'],
            'score'        => $r['score'],
            'total_rows'   => $r['total_rows'],
            'completeness' => $r['completeness']['pct'] ?? null,
        ], $qualityReports);

        return [
            'generated_at'          => date('c'),
            'platform_overview'     => $overview,
            'user_growth_6mo'       => array_map(fn ($r) => ['month' => $r['label']['en'], 'value' => $r['value']], $userGrowth),
            'category_distribution' => array_map(fn ($r) => ['category' => $r['label']['en'], 'value' => $r['value']], $categoryDistribution),
            'category_growth_30d'   => array_map(fn ($r) => [
                'category' => $r['label']['en'], 'current' => $r['current'], 'previous' => $r['previous'], 'growth_pct' => $r['growth_pct'],
            ], $categoryGrowth),
            'top_universities'      => array_map(fn ($r) => ['university' => $r['university']['en'], 'projects' => $r['projects']], $universities),
            'kpis'                  => $kpis,
            // منصة الامتحانات (أرقام مجمّعة بس، من غير أسماء طلاب/دكاترة).
            'exams_and_academic'    => $this->academic->aiDigest(),
            'data_quality_overview' => [
                'overall_score' => $this->quality->overallScore($qualityReports),
                'datasets'      => $qualitySummary,
            ],
        ];
    }
}
