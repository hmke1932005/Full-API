<?php

namespace App\Services;

use App\Models\AIReadinessScore;
use App\Models\Project;
use App\Repositories\AIAnalysisRepository;
use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;
use App\Services\Concerns\ConfiguresAIClient;

/**
 * منقولة من app/Services/AIReadinessScoreService.php القديمة — بند 21.
 * بتقيّم مشروع من 0-100 عبر 4 محاور (تقني/سوقي/ابتكار/عرض وتوثيق) باستخدام
 * Services\Ai\AIClient، ومتخزّنة عبر AIAnalysisRepository. أبدًا مابتختلقش
 * درجة: لو الـ AI client مش مُعد أو النداء فشل، بترمي — المستدعي
 * (AIAnalysisService) بيعرضها كـ "غير متاح"، مش رقم وهمي.
 */
class AIReadinessScoreService
{
    use ConfiguresAIClient;

    private AIClient $client;
    private AIAnalysisRepository $repo;

    public function __construct(AIClient $client, SettingRepository $settings, AIAnalysisRepository $repo)
    {
        $this->client = $client;
        $this->applySettingsOverrides($this->client, $settings);
        $this->repo = $repo;
    }

    public function isAvailable(): bool
    {
        return $this->client->isConfigured();
    }

    /** @throws \RuntimeException لو غير مُعد أو نداء/فك الـ AI فشل */
    public function analyze(Project $project): AIReadinessScore
    {
        $system = 'You are an expert reviewer for a university innovation & startup platform. '
            . 'Score the given student project on readiness, 0-100 per dimension. '
            . 'Respond as JSON: {"overall_score": number, "technical_score": number, "market_score": number, '
            . '"innovation_score": number, "presentation_score": number}. '
            . 'overall_score should reasonably reflect the average of the four dimensions. Be honest and specific '
            . 'to the project described — do not default to generic mid-range numbers.';

        $user = $this->projectContext($project);

        $data = $this->completeJson($this->client, $system, $user);

        $clamp = fn ($v) => max(0.0, min(100.0, (float) ($v ?? 0)));

        return $this->repo->upsertReadiness((int) $project->id, [
            'overall_score'      => $clamp($data['overall_score'] ?? null),
            'technical_score'    => $clamp($data['technical_score'] ?? null),
            'market_score'       => $clamp($data['market_score'] ?? null),
            'innovation_score'   => $clamp($data['innovation_score'] ?? null),
            'presentation_score' => $clamp($data['presentation_score'] ?? null),
        ]);
    }
}
