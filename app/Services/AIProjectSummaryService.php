<?php

namespace App\Services;

use App\Models\Project;
use App\Repositories\AIAnalysisRepository;
use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;
use App\Services\Concerns\ConfiguresAIClient;

/**
 * منقولة من app/Services/AIProjectSummaryService.php القديمة — بند 21.
 * بتولّد ملخص لغة-بسيطة قصير لمشروع من وصفه الكامل، باللغة المطلوبة.
 * متخزّن عبر ai_analysis log (analysis_type = 'summary') — شوف
 * AIAnalysisRepository::summaryFor().
 */
class AIProjectSummaryService
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

    /**
     * @return array{summary:string} القيمة اللي بتتخزن في ai_analysis log بمعرفة AIAnalysisService
     * @throws \RuntimeException لو غير مُعد أو نداء/فك الـ AI فشل
     */
    public function summarize(Project $project, string $locale = 'en'): array
    {
        $language = $locale === 'ar' ? 'Arabic (Modern Standard)' : 'English';
        $system = "Write a plain-language summary (3-4 sentences) of the project below, understandable to someone "
            . "outside the field — such as a university reviewer. Write in {$language}. "
            . 'Respond as JSON: {"summary": string}.';

        $user = $this->projectContext($project);

        $data = $this->completeJson($this->client, $system, $user);
        $summary = trim((string) ($data['summary'] ?? ''));
        if ($summary === '') {
            throw new \RuntimeException('AI provider returned an empty summary.');
        }

        return ['summary' => $summary];
    }
}
