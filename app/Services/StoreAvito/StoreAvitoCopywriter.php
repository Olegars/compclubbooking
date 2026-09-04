<?php

namespace App\Services\StoreAvito;

use App\Models\AiAssistantSetting;
use App\Models\StoreAvitoSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StoreAvitoCopywriter
{
    /**
     * @param  list<array<string, mixed>>  $components
     * @return array{title: string, description: string}
     */
    public function write(string $configId, array $components, int $price, array $xml): array
    {
        $phrase = StoreAvitoSetting::configPhrase($configId);
        $fallback = $this->fallback($configId, $components, $price, $xml, $phrase);

        if (! $this->llmConfigured()) {
            return $fallback;
        }

        try {
            $generated = $this->ask($configId, $components, $price, $xml, $phrase);
        } catch (\Throwable $e) {
            Log::warning('Avito copywriter: '.$e->getMessage());

            return $fallback;
        }

        $title = $this->clampTitle((string) ($generated['title'] ?? ''), $configId);
        $description = trim((string) ($generated['description'] ?? ''));
        if ($title === '' || $description === '') {
            return $fallback;
        }
        if (! str_contains($description, $configId)) {
            $description = rtrim($description)."\n\n".$phrase;
        }

        return ['title' => $title, 'description' => $description];
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @return array{title: string, description: string}
     */
    private function ask(string $configId, array $components, int $price, array $xml, string $phrase): array
    {
        $settings = AiAssistantSetting::forClub(null);
        $bom = [];
        foreach ($components as $row) {
            $bom[] = [
                'type' => $row['type'] ?? '',
                'name' => $row['name'] ?? '',
            ];
        }

        $system = <<<PROMPT
Ты копирайтер магазина готовых игровых ПК для объявлений Avito.
Верни ТОЛЬКО JSON: {"title":"...","description":"..."}
Правила title:
- максимум 50 символов;
- обязательно включи ID конфигурации «{$configId}» как есть;
- без кавычек, без слова Avito, уникальная формулировка.
Правила description:
- живой текст на русском, без копипасты шаблонов;
- перечисли комплектующие из BOM, не выдумывай детали, которых нет;
- цена {$price} ₽;
- в конце ОБЯЗАТЕЛЬНО фраза: {$phrase}
- без markdown, можно абзацы и эмодзи умеренно.
PROMPT;

        $body = [
            'model' => $settings->resolvedLlmModel(),
            'temperature' => 0.85,
            'max_tokens' => 900,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => json_encode(['xml' => $xml, 'bom' => $bom], JSON_UNESCAPED_UNICODE)],
            ],
        ];
        if (str_contains(strtolower($settings->resolvedLlmModel()), 'deepseek')) {
            $body['thinking'] = ['type' => 'disabled'];
        }

        $response = Http::timeout(45)
            ->withToken($settings->resolvedLlmApiKey())
            ->acceptJson()
            ->post($settings->resolvedLlmBaseUrl().'/chat/completions', $body);

        if (! $response->successful()) {
            throw new \RuntimeException('HTTP '.$response->status());
        }

        $message = data_get($response->json(), 'choices.0.message', []);
        $text = trim((string) (is_array($message) ? ($message['content'] ?? '') : ''));
        if ($text === '' && is_array($message)) {
            $text = trim((string) ($message['reasoning_content'] ?? ''));
        }
        if (preg_match('/\{[\s\S]*\}/u', $text, $m)) {
            $text = $m[0];
        }
        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('invalid JSON');
        }

        return $decoded;
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @return array{title: string, description: string}
     */
    public function fallback(string $configId, array $components, int $price, array $xml, string $phrase): array
    {
        $cpu = $xml['CodeProcessor'] ?? 'PC';
        $gpu = $xml['CodeVideocard'] ?? '';
        $ram = $xml['RamSize'] ?? '';
        $title = $this->clampTitle(trim("ПК {$cpu} {$gpu} {$ram} {$configId}"), $configId);

        $lines = ["Игровой компьютер {$price} ₽", ''];
        foreach ($components as $row) {
            $lines[] = '• '.($row['name'] ?? '');
        }
        $lines[] = '';
        $lines[] = $phrase;

        return [
            'title' => $title,
            'description' => implode("\n", $lines),
        ];
    }

    public function clampTitle(string $title, string $configId): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        $title = str_replace(['«', '»', '"'], '', $title);
        if (! str_contains($title, $configId)) {
            $title = trim(mb_substr($title, 0, 50 - mb_strlen($configId) - 1)).' '.$configId;
        }
        if (mb_strlen($title) <= 50) {
            return $title;
        }
        $keep = 50 - mb_strlen($configId) - 1;
        $head = trim(mb_substr($title, 0, $keep));
        $head = preg_replace('/[\s\-]+$/u', '', $head) ?? $head;

        return $head.' '.$configId;
    }

    private function llmConfigured(): bool
    {
        return AiAssistantSetting::forClub(null)->resolvedLlmApiKey() !== '';
    }
}
