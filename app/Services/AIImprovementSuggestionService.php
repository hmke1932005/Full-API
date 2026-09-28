<?php

namespace App\Services;

use App\Models\Project;
use App\Repositories\AIAnalysisRepository;
use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;
use App\Services\Concerns\ConfiguresAIClient;

/**
 * منقولة من app/Services/AIImprovementSuggestionService.php القديمة —
 * بند 21. بتولّد اقتراحات تحسين ملموسة وخاصة بالمشروع، كل واحدة بفئة
 * وأولوية. كل run جديد بيستبدل الدفعة القديمة بالكامل (شوف
 * AIAnalysisRepository::replaceSuggestions()).
 */
class AIImprovementSuggestionService
{
    use ConfiguresAIClient;

    private const CATEGORIES = ['technical', 'presentation', 'market_fit', 'documentation', 'other'];
    private const PRIORITIES = ['low', 'medium', 'high'];

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

    /**
     * @return \App\Models\ImprovementSuggestion[]
     * @throws \RuntimeException لو غير مُعد أو نداء/فك الـ AI فشل
     */
    public function suggest(Project $project): array
    {
        $system = 'You give a student 3-5 concrete, actionable suggestions to improve their project before '
            . 'submitting it for university approval or showcasing it publicly. Respond as JSON: '
            . '{"suggestions": [{"suggestion": string (1 concrete sentence, specific to THIS project, not generic advice), '
            . '"category": string (one of: technical, presentation, market_fit, documentation, other), '
            . '"priority": string (one of: low, medium, high)}]}.';

        $user = $this->projectContext($project);

        $data = $this->completeJson($this->client, $system, $user);
        $raw = (array) ($data['suggestions'] ?? []);

        $clean = [];
        foreach ($raw as $s) {
            if (!is_array($s) || empty($s['suggestion'])) {
                continue;
            }
            $category = in_array($s['category'] ?? '', self::CATEGORIES, true) ? $s['category'] : 'other';
            $priority = in_array($s['priority'] ?? '', self::PRIORITIES, true) ? $s['priority'] : 'medium';
            $clean[] = [
                'suggestion' => mb_substr((string) $s['suggestion'], 0, 500),
                'category'   => $category,
                'priority'   => $priority,
            ];
        }

        if (!$clean) {
            throw new \RuntimeException('AI provider returned no usable suggestions.');
        }

        return $this->repo->replaceSuggestions((int) $project->id, $clean)->all();
    }
}
