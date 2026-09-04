<?php

namespace App\Services\StoreAvito;

use App\Models\AiAssistantSetting;
use App\Models\StoreAvitoProductAttr;
use App\Models\StoreSupplierCatalogProduct;
use App\Services\StoreSupplierCatalogSearchService;
use App\Support\AvitoPcXmlDict;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StoreAvitoCatalogAttrService
{
    public const TYPES = ['cpu', 'motherboard', 'ram', 'gpu', 'storage_ssd', 'psu', 'cooler', 'case'];

    public function __construct(
        private readonly StoreAvitoCatalogAttrParser $parser,
        private readonly StoreSupplierCatalogSearchService $search,
    ) {}

    /**
     * @return Collection<int, StoreSupplierCatalogProduct>
     */
    public function inStock(string $type, int $limit = 250): Collection
    {
        $q = StoreSupplierCatalogProduct::query()
            ->whereNotNull('price')
            ->where('price', '>', 0)
            ->where(function ($w) {
                $w->whereNull('stock_qty')->orWhere('stock_qty', '>', 0);
            });

        $ids = $this->search->categoryIdsForType($type);
        if (is_array($ids)) {
            if ($ids === []) {
                return collect();
            }
            $q->whereIn('category_external_id', $ids);
        }

        return $q->orderBy('price')->limit($limit)->get();
    }

    /**
     * Доразметить товары без attrs. DeepSeek — только дыры, которые эвристика не закрыла.
     */
    public function enrichType(string $type, int $limit = 250): int
    {
        $products = $this->inStock($type, $limit);
        $done = 0;
        $needLlm = [];

        foreach ($products as $product) {
            $existing = StoreAvitoProductAttr::query()->where('sku', $product->sku)->first();
            if ($existing && filled($existing->avito_brand) && filled($existing->avito_model)) {
                continue;
            }

            $parsed = $this->parser->parse(
                $type,
                (string) $product->name,
                (string) ($product->part ?? ''),
                (string) ($product->vendor ?? ''),
            );
            $parsed['type'] = $type === 'storage_ssd' ? 'ssd' : $type;

            $complete = $this->isComplete($type, $parsed);
            $this->upsert((int) $product->sku, $parsed, 'heuristic');
            $done++;

            if (! $complete) {
                $needLlm[] = $product;
            }
        }

        if ($needLlm !== [] && $this->llmConfigured()) {
            $done += $this->fillWithLlm($type, $needLlm);
        }

        return $done;
    }

    public function enrichPool(): int
    {
        $n = 0;
        foreach (self::TYPES as $type) {
            $n += $this->enrichType($type);
        }

        return $n;
    }

    /**
     * @param  list<StoreSupplierCatalogProduct>  $products
     */
    private function fillWithLlm(string $type, array $products): int
    {
        $updated = 0;
        foreach (array_chunk($products, 20) as $chunk) {
            try {
                $rows = $this->askLlm($type, $chunk);
            } catch (\Throwable $e) {
                Log::warning('Avito attrs DeepSeek: '.$e->getMessage());
                continue;
            }
            foreach ($chunk as $product) {
                $row = $rows[(int) $product->sku] ?? null;
                if (! is_array($row)) {
                    continue;
                }
                $parsed = $this->parser->parse(
                    $type,
                    (string) $product->name,
                    (string) ($product->part ?? ''),
                    (string) ($product->vendor ?? ''),
                );
                $merged = array_merge($parsed, array_filter([
                    'socket' => $row['socket'] ?? null,
                    'ddr' => $row['ddr'] ?? null,
                    'ram_gb' => isset($row['ram_gb']) ? (int) $row['ram_gb'] : null,
                    'wattage' => isset($row['wattage']) ? (int) $row['wattage'] : null,
                    'form' => $row['form'] ?? null,
                    'avito_brand' => $row['avito_brand'] ?? null,
                    'avito_model' => $row['avito_model'] ?? null,
                    'avito_code' => $row['avito_code'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''));
                $merged['type'] = $type === 'storage_ssd' ? 'ssd' : $type;
                $merged = $this->clamp($type, $merged);
                $this->upsert((int) $product->sku, $merged, 'deepseek');
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * @param  list<StoreSupplierCatalogProduct>  $chunk
     * @return array<int, array<string, mixed>>
     */
    private function askLlm(string $type, array $chunk): array
    {
        $settings = AiAssistantSetting::forClub(null);
        $payload = [];
        foreach ($chunk as $p) {
            $payload[] = [
                'sku' => (int) $p->sku,
                'name' => (string) $p->name,
                'part' => (string) ($p->part ?? ''),
                'vendor' => (string) ($p->vendor ?? ''),
            ];
        }

        $dictHint = match ($type) {
            'cpu' => 'avito_brand из '.implode('/', AvitoPcXmlDict::processorBrands())
                .'; avito_model из '.implode(', ', AvitoPcXmlDict::processorModels())
                .'; avito_code — код вроде 12400F/7600X.',
            'gpu' => 'avito_brand NVIDIA|AMD|Intel; avito_model строго из списка GeForce RTX … / Radeon RX …',
            'ram' => 'ram_gb число, ddr DDR4|DDR5, avito_code вида «32 ГБ».',
            'motherboard' => 'socket AM4|AM5|LGA1700|LGA1851, ddr DDR4|DDR5, avito_brand ASUS|MSI|Gigabyte|ASRock.',
            default => 'заполни бренд/модель по названию.',
        };

        $system = <<<PROMPT
Ты размечаешь комплектующие ПК для XML Avito (системные блоки).
Верни ТОЛЬКО JSON-массив:
[{"sku":1,"socket":"AM5|AM4|LGA1700|LGA1851|null","ddr":"DDR4|DDR5|null","ram_gb":32,"wattage":650,"form":"atx|matx|itx|null","avito_brand":"...","avito_model":"...","avito_code":"..."}]
Правила: {$dictHint}
Не выдумывай поля, которых нет в названии — тогда null.
PROMPT;

        $body = [
            'model' => $settings->resolvedLlmModel(),
            'temperature' => 0.1,
            'max_tokens' => 2500,
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
            throw new \RuntimeException('HTTP '.$response->status().' '.$response->body());
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
            throw new \RuntimeException('invalid JSON from LLM');
        }

        $bySku = [];
        foreach ($decoded as $row) {
            if (is_array($row) && isset($row['sku'])) {
                $bySku[(int) $row['sku']] = $row;
            }
        }

        return $bySku;
    }

    private function upsert(int $sku, array $parsed, string $source): void
    {
        StoreAvitoProductAttr::query()->updateOrCreate(
            ['sku' => $sku],
            [
                'type' => $parsed['type'] ?? 'other',
                'socket' => $parsed['socket'] ?? null,
                'ddr' => $parsed['ddr'] ?? null,
                'ram_gb' => $parsed['ram_gb'] ?? null,
                'wattage' => $parsed['wattage'] ?? null,
                'form' => $parsed['form'] ?? null,
                'avito_brand' => $parsed['avito_brand'] ?? null,
                'avito_model' => $parsed['avito_model'] ?? null,
                'avito_code' => $parsed['avito_code'] ?? null,
                'source' => $source,
                'mapped_at' => now(),
            ]
        );
    }

    private function isComplete(string $type, array $parsed): bool
    {
        return match ($type) {
            'cpu' => filled($parsed['avito_brand']) && filled($parsed['avito_model']) && filled($parsed['avito_code']) && filled($parsed['socket']),
            'gpu' => filled($parsed['avito_brand']) && filled($parsed['avito_model']),
            'ram' => (int) ($parsed['ram_gb'] ?? 0) > 0 && filled($parsed['ddr']),
            'motherboard' => filled($parsed['socket']) && filled($parsed['avito_brand']),
            default => filled($parsed['avito_model']),
        };
    }

    private function clamp(string $type, array $parsed): array
    {
        if ($type === 'cpu') {
            $parsed['avito_brand'] = AvitoPcXmlDict::closest(AvitoPcXmlDict::processorBrands(), $parsed['avito_brand'] ?? null);
            $parsed['avito_model'] = AvitoPcXmlDict::closest(AvitoPcXmlDict::processorModels(), $parsed['avito_model'] ?? null);
            $parsed['avito_code'] = AvitoPcXmlDict::closest(AvitoPcXmlDict::processorCodes(), $parsed['avito_code'] ?? null);
        }
        if ($type === 'gpu') {
            $parsed['avito_brand'] = AvitoPcXmlDict::closest(AvitoPcXmlDict::videocardBrands(), $parsed['avito_brand'] ?? null, 'NVIDIA');
            $parsed['avito_model'] = AvitoPcXmlDict::closest(AvitoPcXmlDict::videocardModels(), $parsed['avito_model'] ?? null);
        }
        if ($type === 'motherboard') {
            $parsed['avito_brand'] = AvitoPcXmlDict::closest(AvitoPcXmlDict::motherboardBrands(), $parsed['avito_brand'] ?? null, 'Другой');
        }
        if ($type === 'ram' && ! empty($parsed['ram_gb'])) {
            $parsed['avito_code'] = AvitoPcXmlDict::ramSizeForGb((int) $parsed['ram_gb']);
        }

        return $parsed;
    }

    private function llmConfigured(): bool
    {
        return AiAssistantSetting::forClub(null)->resolvedLlmApiKey() !== '';
    }
}
