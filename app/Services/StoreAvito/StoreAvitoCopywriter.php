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
                $lead = trim((string) ($got[$id]['lead'] ?? ''));
                $out[$id] = $this->assemble($id, $title, $lead, $job['components'], (int) $job['price'], $job['xml'], $phrase);
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
     * @return array<string, array{title?:string, lead?:string}>
     */
    private function askMany(array $chunk): array
    {
        $settings = AiAssistantSetting::forClub(null);
        $payload = [];
        foreach ($chunk as $job) {
            $bom = [];
            foreach ($job['components'] as $row) {
                $bom[] = (string) ($row['name'] ?? '');
            }
            $id = (string) $job['config_id'];
            $payload[] = [
                'config_id' => $id,
                'price' => (int) $job['price'],
                'title_hints' => array_values(array_filter([
                    $job['xml']['CodeProcessor'] ?? null,
                    $job['xml']['CodeVideocard'] ?? null,
                    $job['xml']['RamSize'] ?? null,
                ])),
                'bom' => $bom,
            ];
        }

        $system = <<<'PROMPT'
Ты копирайтер магазина готовых игровых ПК для Avito.
Верни ТОЛЬКО JSON-массив:
[{"config_id":"...","title":"...","lead":"..."}]
title: максимум 50 символов, обязательно config_id как есть, без кавычек, без слова Avito.
Можно взять короткие намёки из title_hints (процессоры/видеокарты из BOM).
lead: 1–2 предложения на русском, общее впечатление для геймера.
ЗАПРЕЩЕНО в lead называть модели, бренды, объёмы RAM/SSD/VRAM, Intel, AMD, NVIDIA, Ryzen, Core, RTX — комплектующие ниже подставит программа.
PROMPT;

        $body = [
            'model' => $settings->resolvedLlmModel(),
            'temperature' => 0.85,
            'max_tokens' => min(2000, 250 * count($chunk)),
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
     * @param  array<string, string>  $xml
     * @return array{title: string, description: string}
     */
    public function fallback(string $configId, array $components, int $price, array $xml, string $phrase): array
    {
        return $this->assemble($configId, '', '', $components, $price, $xml, $phrase);
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @param  array<string, string>  $xml
     * @return array{title: string, description: string}
     */
    private function assemble(string $configId, string $title, string $lead, array $components, int $price, array $xml, string $phrase): array
    {
        $title = $this->clampTitle($title, $configId);
        if ($title === $configId || trim($title) === '') {
            $cpu = $xml['CodeProcessor'] ?? 'PC';
            $gpu = $xml['CodeVideocard'] ?? '';
            $ram = $xml['RamSize'] ?? '';
            $title = $this->clampTitle(trim("ПК {$cpu} {$gpu} {$ram} {$configId}"), $configId);
        }

        $lines = [];
        if ($lead !== '') {
            $lines[] = $lead;
            $lines[] = '';
        }
        $lines[] = 'Цена '.$price.' ₽. Комплектация:';
        $lines[] = '';
        foreach ($components as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '') {
                $lines[] = '• '.$name;
            }
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
        if ($title === '') {
            return $configId;
        }
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
