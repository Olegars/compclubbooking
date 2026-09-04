<?php

namespace App\Services\StoreAvito;

use App\Models\AiAssistantSetting;
use App\Models\StoreAvitoSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StoreAvitoCopywriter
{
    /**
     * @param  list<array{config_id:string, components:list<array<string,mixed>>, price:int, xml:array<string,string>}>  $jobs
     * @return array<string, array{title: string, description: string}>
     */
    public function writeMany(array $jobs): array
    {
        $out = [];
        foreach (array_chunk($jobs, 8) as $chunk) {
            $got = [];
            if ($this->llmConfigured()) {
                try {
                    $got = $this->askMany($chunk);
                } catch (\Throwable $e) {
                    Log::warning('Avito copywriter batch: '.$e->getMessage());
                }
            }
            foreach ($chunk as $job) {
                $id = (string) $job['config_id'];
                $phrase = StoreAvitoSetting::configPhrase($id);
                $title = $this->clampTitle((string) ($got[$id]['title'] ?? ''), $id);
                $description = trim((string) ($got[$id]['description'] ?? ''));
                if ($title === '' || $description === '') {
                    $out[$id] = $this->fallback($id, $job['components'], (int) $job['price'], $job['xml'], $phrase);

                    continue;
                }
                if (! str_contains($description, $id)) {
                    $description = rtrim($description)."\n\n".$phrase;
                }
                $out[$id] = ['title' => $title, 'description' => $description];
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @return array{title: string, description: string}
     */
    public function write(string $configId, array $components, int $price, array $xml): array
    {
        $many = $this->writeMany([[
            'config_id' => $configId,
            'components' => $components,
            'price' => $price,
            'xml' => $xml,
        ]]);

        return $many[$configId];
    }

    /**
     * @param  list<array{config_id:string, components:list<array<string,mixed>>, price:int, xml:array<string,string>}>  $chunk
     * @return array<string, array{title?:string, description?:string}>
     */
    private function askMany(array $chunk): array
    {
        $settings = AiAssistantSetting::forClub(null);
        $payload = [];
        foreach ($chunk as $job) {
            $bom = [];
            foreach ($job['components'] as $row) {
                $bom[] = [
                    'type' => $row['type'] ?? '',
                    'name' => $row['name'] ?? '',
                ];
            }
            $id = (string) $job['config_id'];
            $payload[] = [
                'config_id' => $id,
                'price' => (int) $job['price'],
                'xml' => $job['xml'],
                'bom' => $bom,
                'phrase' => StoreAvitoSetting::configPhrase($id),
            ];
        }

        $system = <<<'PROMPT'
Ты копирайтер магазина готовых игровых ПК для объявлений Avito.
Верни ТОЛЬКО JSON-массив, по одному объекту на каждый config_id:
[{"config_id":"...","title":"...","description":"..."}]
Правила title:
- максимум 50 символов;
- обязательно включи config_id как есть;
- без кавычек, без слова Avito, формулировки разные у разных объявлений.
Правила description:
- живой текст на русском;
- перечисли комплектующие из BOM, не выдумывай детали;
- укажи цену;
- в конце ОБЯЗАТЕЛЬНО поле phrase из запроса;
- без markdown, можно абзацы и эмодзи умеренно.
PROMPT;

        $body = [
            'model' => $settings->resolvedLlmModel(),
            'temperature' => 0.85,
            'max_tokens' => min(8000, 700 * count($chunk)),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
            ],
        ];
        if (str_contains(strtolower($settings->resolvedLlmModel()), 'deepseek')) {
            $body['thinking'] = ['type' => 'disabled'];
        }

        $response = Http::timeout(60)
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
        if (preg_match('/\[[\s\S]*\]/u', $text, $m)) {
            $text = $m[0];
        }
        $decoded = json_decode($text, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('invalid JSON');
        }

        $byId = [];
        foreach ($decoded as $row) {
            if (is_array($row) && isset($row['config_id'])) {
                $byId[(string) $row['config_id']] = $row;
            }
        }

        return $byId;
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
