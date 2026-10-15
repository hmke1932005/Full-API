<?php

namespace App\Services;

use App\Repositories\SettingRepository;
use App\Services\Ai\AIClient;
use App\Services\Concerns\ConfiguresAIClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ترجمة الفيديو التلقائية.
 *
 *  1) الفرونت بيفك صوت الفيديو في المتصفح (16kHz mono WAV) ويبعته على قطع قصيرة — فمفيش اعتماد على ffmpeg ولا على مسار الملف.
 *  2) هنا: كل قطعة بتتفرّغ بـ Whisper (/audio/transcriptions، verbose_json → segments بتوقيتات).
 *  3) لو لغة الكلام غير اللغة المطلوبة، النص بيترجم دفعات عبر نفس عميل الـ AI (AIClient::complete).
 *
 * الفشل بيترمي كـ RuntimeException والكنترولر هو اللي بيحوّله لرد خطأ (مفيش ترجمة مختلقة).
 */
class SubtitleService
{
    use ConfiguresAIClient;

    /** كود اللغة => اسمها (للـ prompt) + مقارنة بالـ language اللي Whisper بيرجّعه (اسم إنجليزي). */
    public const LANGUAGES = [
        'ar' => 'Arabic', 'en' => 'English', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish',
        'it' => 'Italian', 'tr' => 'Turkish', 'ru' => 'Russian', 'pt' => 'Portuguese', 'zh' => 'Chinese',
        'ja' => 'Japanese', 'ko' => 'Korean', 'hi' => 'Hindi', 'ur' => 'Urdu', 'fa' => 'Persian',
    ];

    private const BATCH = 40; // سطور الترجمة في الطلب الواحد

    public function __construct(private AIClient $client, private SettingRepository $settings)
    {
        $this->applySettingsOverrides($this->client, $this->settings);
    }

    /** @return array{0:string,1:string} [base_url, api_key] لنداء التفريغ */
    private function sttCredentials(): array
    {
        $cfg = (array) config('ai.transcribe', []);
        $base = (string) ($cfg['base_url'] ?? '');
        $key  = (string) ($cfg['api_key'] ?? '');
        if ($base === '' || $key === '') {
            // مفيش مزوّد تفريغ منفصل → نفس مزوّد الشات (لازم يكون مفعّل ومضبوط).
            if (!$this->client->isConfigured()) {
                return ['', ''];
            }
            $base = $this->client->baseUrl();
            $key  = $this->client->apiKey();
        }
        return [$base, $key];
    }

    public function available(): bool
    {
        [$base, $key] = $this->sttCredentials();
        return $base !== '' && $key !== '';
    }

    /** أقصى طول قطعة (ثواني) يقدر السيرفر يستقبله: min(إعداد المشروع، حد Whisper، حدود PHP) — WAV 16k mono = 32000 بايت/ث. */
    public function maxChunkSeconds(): int
    {
        $cfg   = (array) config('ai.transcribe', []);
        $bytes = min(
            (int) ($cfg['max_upload_kb'] ?? 24000) * 1024,
            $this->iniBytes('upload_max_filesize'),
            $this->iniBytes('post_max_size')
        );
        $byBytes = (int) floor((($bytes * 0.9) - 44) / 32000);
        $wanted  = (int) ($cfg['max_chunk_seconds'] ?? 240);
        return max(15, min($wanted, $byBytes, 600));
    }

    /**
     * @return array{language:?string, translated:bool, cues:array<int,array{s:float,e:float,text:string}>}
     * @throws \RuntimeException
     */
    public function generate(UploadedFile $audio, ?string $target, ?string $sourceHint = null): array
    {
        $raw      = $this->transcribe($audio, $sourceHint);
        $cues     = $raw['cues'];
        $detected = $raw['language'];

        $translated = false;
        $wantsTranslation = $target !== null && $target !== '' && $target !== 'orig' && isset(self::LANGUAGES[$target]);
        if ($cues && $wantsTranslation && !$this->sameLanguage($detected, $target)) {
            if ($this->client->isConfigured()) {
                [$cues, $translated] = $this->translate($cues, $target);
            }
        } elseif ($wantsTranslation) {
            $translated = true; // الكلام أصلاً باللغة المطلوبة
        }

        return ['language' => $detected, 'translated' => $translated, 'cues' => $cues];
    }

    /**
     * @return array{language:?string, cues:array<int,array{s:float,e:float,text:string}>}
     * @throws \RuntimeException
     */
    private function transcribe(UploadedFile $audio, ?string $sourceHint): array
    {
        [$base, $key] = $this->sttCredentials();
        if ($base === '' || $key === '') {
            throw new \RuntimeException('Transcription provider is not configured.');
        }
        $cfg   = (array) config('ai.transcribe', []);
        $path  = $audio->getRealPath();
        $handle = $path ? @fopen($path, 'r') : false;
        if (!$handle) {
            throw new \RuntimeException('Uploaded audio could not be read.');
        }

        $fields = [
            'model'           => (string) ($cfg['model'] ?? 'whisper-1'),
            'response_format' => 'verbose_json',
            'temperature'     => '0',
        ];
        if ($sourceHint !== null && $sourceHint !== '') {
            $fields['language'] = $sourceHint;
        }

        try {
            $res = Http::withToken($key)
                ->acceptJson()
                ->timeout(max(20, (int) ($cfg['timeout'] ?? 120)))
                ->attach('file', $handle, 'audio.wav')
                ->post($base . '/audio/transcriptions', $fields);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Transcription request failed: ' . $e->getMessage());
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if ($res->failed()) {
            throw new \RuntimeException('Transcription provider returned HTTP ' . $res->status() . ': ' . mb_substr((string) $res->body(), 0, 300));
        }

        $json = (array) $res->json();
        $cues = [];
        foreach ((array) ($json['segments'] ?? []) as $seg) {
            $text = trim((string) ($seg['text'] ?? ''));
            // Whisper بيهلوس على الصمت/الموسيقى: نستبعد القطع اللي احتمال إنها \"مفيش كلام\" عالي.
            if ($text === '' || (float) ($seg['no_speech_prob'] ?? 0) > 0.6) {
                continue;
            }
            $start = max(0.0, (float) ($seg['start'] ?? 0));
            $end   = max($start + 0.4, (float) ($seg['end'] ?? $start));
            $cues[] = ['s' => round($start, 2), 'e' => round($end, 2), 'text' => $text];
        }
        if (!$cues && trim((string) ($json['text'] ?? '')) !== '' && empty($json['segments'])) {
            // مزوّد بيرجّع نص بس من غير segments.
            $cues[] = ['s' => 0.0, 'e' => max(1.0, (float) ($json['duration'] ?? 5)), 'text' => trim((string) $json['text'])];
        }

        $lang = isset($json['language']) ? strtolower((string) $json['language']) : null;
        return ['language' => $lang, 'cues' => $cues];
    }

    /**
     * @param array<int,array{s:float,e:float,text:string}> $cues
     * @return array{0:array<int,array{s:float,e:float,text:string}>, 1:bool} [cues, كل الدفعات اتترجمت؟]
     */
    private function translate(array $cues, string $target): array
    {
        $name = self::LANGUAGES[$target];
        $all  = true;
        $out  = $cues;
        foreach (array_chunk(array_keys($cues), self::BATCH) as $idxs) {
            $texts = array_map(fn ($i) => $cues[$i]['text'], $idxs);
            $translated = $this->translateBatch($texts, $name);
            if ($translated === null) {
                $all = false; // الدفعة دي بتفضل بلغتها الأصلية بدل ما تضيع.
                continue;
            }
            foreach ($idxs as $k => $i) {
                $out[$i]['text'] = $translated[$k];
            }
        }
        return [$out, $all];
    }

    /** @param string[] $texts @return string[]|null */
    private function translateBatch(array $texts, string $languageName): ?array
    {
        $n = count($texts);
        $system = "You are a professional subtitle translator. The user sends a JSON array of {$n} subtitle lines. "
            . "Translate every line into {$languageName}. Keep the meaning, tone and brevity suited to on-screen subtitles; "
            . "keep names, numbers, code and technical terms accurate. Do not merge, split, reorder, add or drop lines. "
            . "Reply with ONLY a JSON array of exactly {$n} strings. No markdown fences, no commentary.";

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $raw = $this->client->complete($system, json_encode($texts, JSON_UNESCAPED_UNICODE), 0.2);
            } catch (\RuntimeException $e) {
                Log::warning('Subtitle translation request failed', ['attempt' => $attempt, 'error' => mb_substr($e->getMessage(), 0, 300)]);
                continue;
            }
            $arr = $this->parseJsonArray($raw);
            if ($arr !== null && count($arr) === $n) {
                return array_map(fn ($v) => trim((string) $v), array_values($arr));
            }
        }
        return null;
    }

    private function parseJsonArray(string $raw): ?array
    {
        $text = trim((string) preg_replace('/<think>.*?<\/think>/is', '', $raw));
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $text, $m)) {
            $text = trim($m[1]);
        }
        $decoded = json_decode($text, true);
        if (is_array($decoded) && array_is_list($decoded)) {
            return $decoded;
        }
        $start = strpos($text, '[');
        $end   = strrpos($text, ']');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($decoded) && array_is_list($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    private function sameLanguage(?string $detected, string $target): bool
    {
        if ($detected === null || $detected === '') {
            return false;
        }
        $detected = strtolower($detected);
        return $detected === $target || $detected === strtolower(self::LANGUAGES[$target] ?? '');
    }

    private function iniBytes(string $key): int
    {
        $v = trim((string) ini_get($key));
        if ($v === '' || (int) $v <= 0) {
            return PHP_INT_MAX;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
