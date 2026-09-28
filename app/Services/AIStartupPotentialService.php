<?php

namespace App\Services;

use App\Models\Project;
use App\Models\StartupPotential;
use App\Repositories\AIAnalysisRepository;
use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;
use App\Services\Concerns\ConfiguresAIClient;

/**
 * منقولة من app/Services/AIStartupPotentialService.php القديمة — بند 21.
 * بتقيّم إمكانية الاستثمار في مشروع: درجة 0-100، تقدير حجم السوق، الميزة
 * التنافسية، وعوامل الخطر الرئيسية.
 */
class AIStartupPotentialService
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
    public function assess(Project $project): StartupPotential
    {
        $system = 'You assess the investment/startup potential of a university innovation project for a general commercialization audience. '
            . 'Respond as JSON: {"potential_score": number (0-100), '
            . '"market_size_estimate": string (short phrase, e.g. "Niche / regional" or "Large / global"), '
            . '"competitive_edge": string (1-2 sentences, specific to this project), '
            . '"risk_factors": string[] (2-4 short concrete risks)}.';

        $user = $this->projectContext($project);

        $data = $this->completeJson($this->client, $system, $user);

        return $this->repo->upsertStartupPotential((int) $project->id, [
            'potential_score'      => max(0.0, min(100.0, (float) ($data['potential_score'] ?? 0))),
            'market_size_estimate' => mb_substr((string) ($data['market_size_estimate'] ?? ''), 0, 100),
            'competitive_edge'     => (string) ($data['competitive_edge'] ?? ''),
            'risk_factors'         => array_values(array_map('strval', (array) ($data['risk_factors'] ?? []))),
        ]);
    }
}
