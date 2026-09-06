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

    /** DeepSeek при синке каталога — как корпуса, только эти типы. */
    public const STD_TYPES = ['cpu', 'gpu', 'motherboard', 'ram', 'storage_ssd', 'psu'];

    public function __construct(
        private readonly StoreAvitoCatalogAttrParser $parser,
        private readonly StoreSupplierCatalogSearchService $search,
        private readonly StoreAvitoDictMatcher $matcher,
    ) {}

    public function isConfigured(): bool
    {
        return $this->llmConfigured();
    }

    /**
     * @return Collection<int, StoreSupplierCatalogProduct>
     */
    public function inStock(string $type, int $limit = 250): Collection
    {
        $q = StoreSupplierCatalogProduct::query()
            ->whereNotNull('price')
            ->where('price', '>', 0);

        $ids = $this->categoryIds($type);
        if (is_array($ids)) {
            if ($ids === []) {
                return collect();
            }
            $q->whereIn('category_external_id', $ids);
        }

        return $q->orderBy('price')->limit($limit)->get();
    }

    /**
     * Как корпуса: уже размеченные не трогаем, DeepSeek только дыры.
     *
     * @return array{total:int, pending_before:int, classified:int}
     */
    public function classifyAll(bool $force = false): array
    {
        $total = 0;
        $pending = 0;
        $classified = 0;
        foreach (self::STD_TYPES as $type) {
            $products = $this->inCategory($type);
            $total += $products->count();
            $result = $this->classifyProducts($type, $products, $force);
            $pending += $result['pending_before'];
            $classified += $result['classified'];
        }

        return [
            'total' => $total,
            'pending_before' => $pending,
            'classified' => $classified,
        ];
    }

    /**
     * @param  Collection<int, StoreSupplierCatalogProduct>  $products
     * @return array{total:int, pending_before:int, classified:int}
     */
    public function classifyProducts(string $type, Collection $products, bool $force = false, bool $useLlm = true): array
    {
        if ($force && $products->isNotEmpty()) {
            StoreAvitoProductAttr::query()->whereIn('sku', $products->pluck('sku')->all())->delete();
        }

        $needLlm = [];
        $pending = 0;
        $done = 0;
        foreach ($products as $product) {
            $existing = StoreAvitoProductAttr::query()->where('sku', $product->sku)->first();
            if ($existing && $this->rowComplete($type, $existing)) {
                continue;
            }
            $pending++;
            $parsed = $this->parser->parse(
                $type,
                (string) $product->name,
                (string) ($product->part ?? ''),
                (string) ($product->vendor ?? ''),
            );
            $parsed['type'] = $type === 'storage_ssd' ? 'ssd' : $type;
            $this->upsert((int) $product->sku, $parsed, 'heuristic');
            $done++;
            if (! $this->isComplete($type, $parsed)) {
                $needLlm[] = $product;
            }
        }

        if ($useLlm && $needLlm !== [] && $this->llmConfigured()) {
            $done += $this->fillWithLlm($type, $needLlm);
        }

        return [
            'total' => $products->count(),
            'pending_before' => $pending,
            'classified' => $done,
        ];
    }

    /**
     * Доразметить товары без attrs. DeepSeek — только если $useLlm и эвристика не закрыла дыры.
     */
    public function enrichType(string $type, int $limit = 250, bool $useLlm = true): int
    {
        return $this->classifyProducts($type, $this->inStock($type, $limit), false, $useLlm)['classified'];
    }

    public function enrichPool(bool $useLlm = true): int
    {
        $n = 0;
        foreach (self::STD_TYPES as $type) {
            $n += $this->enrichType($type, 8000, $useLlm);
        }

        return $n;
    }

    /**
     * @param  list<StoreSupplierCatalogProduct>  $products
     */
    private function fillWithLlm(string $type, array $products): int
    {
        $updated = 0;
        foreach (array_chunk($products, 8) as $chunk) {
            $updated += $this->fillChunk($type, $chunk);
        }

        return $updated;
    }

    /**
     * @param  list<StoreSupplierCatalogProduct>  $chunk
     */
    private function fillChunk(string $type, array $chunk): int
    {
        try {
            $rows = $this->askLlm($type, $chunk);
        } catch (\Throwable $e) {
            $this->warnLlm($type, $e->getMessage(), count($chunk));
            if (count($chunk) > 1) {
                $mid = (int) ceil(count($chunk) / 2);

                return $this->fillChunk($type, array_slice($chunk, 0, $mid))
                    + $this->fillChunk($type, array_slice($chunk, $mid));
            }

            return 0;
        }

        $updated = 0;
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
            'cpu' => 'avito_brand Intel|AMD; avito_model Core i5|Ryzen 5; avito_code — точный индекс из названия: 7500F не 7500, 12400F, 7800X3D. «Ryzen 5 7500F» → code 7500F, socket AM5.',
            'gpu' => 'avito_brand — производитель карты (ZOTAC, Palit, MSI, ASUS, Gigabyte), НЕ NVIDIA/AMD. avito_model — полное имя из прайса. avito_code — чип: RTX 5060, RTX 5060 Ti, RX 9070 XT (Ti/Super только если есть в названии).',
            'ram' => 'ram_gb число комплекта, ddr DDR4|DDR5, avito_code вида «32 ГБ».',
            'motherboard' => 'socket AM4|AM5|LGA1700|LGA1851, ddr DDR4|DDR5, avito_brand ASUS|MSI|Gigabyte|ASRock, avito_model — полное имя платы.',
            'psu' => 'wattage — ваттность блока (500, 550, 650, 750, 850). Из «GPS-500A8», «500Вт», «500 W» бери 500. Не путай с 80 PLUS.',
            'storage_ssd' => 'ram_gb = объём в ГБ (256/512/1024), avito_model из названия.',
            default => 'заполни бренд/модель по названию.',
        };

        $system = <<<PROMPT
Ты размечаешь комплектующие ПК для XML Avito (системные блоки).
Верни ТОЛЬКО JSON-массив, без markdown и без текста вокруг:
[{"sku":1,"socket":"AM5|AM4|LGA1700|LGA1851|null","ddr":"DDR4|DDR5|null","ram_gb":32,"wattage":650,"form":"atx|matx|itx|null","avito_brand":"...","avito_model":"...","avito_code":"..."}]
Правила: {$dictHint}
Не выдумывай поля, которых нет в названии — тогда null.
PROMPT;

        $body = [
            'model' => $settings->resolvedLlmModel(),
            'temperature' => 0.1,
            'max_tokens' => 4000,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
            ],
        ];
        if (str_contains(strtolower($settings->resolvedLlmModel()), 'deepseek')) {
            $body['thinking'] = ['type' => 'disabled'];
        }

        $response = Http::timeout(90)
            ->withToken($settings->resolvedLlmApiKey())
            ->acceptJson()
            ->post($settings->resolvedLlmBaseUrl().'/chat/completions', $body);

        if (! $response->successful()) {
            throw new \RuntimeException('HTTP '.$response->status().' '.$response->body());
        }

        $message = data_get($response->json(), 'choices.0.message', []);
        $text = $this->llmMessageText(is_array($message) ? $message : []);
        $decoded = $this->decodeLlmRows($text);

        $bySku = [];
        foreach ($decoded as $row) {
            if (is_array($row) && isset($row['sku'])) {
                $bySku[(int) $row['sku']] = $row;
            }
        }

        return $bySku;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function llmMessageText(array $message): string
    {
        $content = $message['content'] ?? '';
        if (is_array($content)) {
            $bits = [];
            foreach ($content as $part) {
                if (is_string($part)) {
                    $bits[] = $part;
                } elseif (is_array($part) && isset($part['text'])) {
                    $bits[] = (string) $part['text'];
                }
            }
            $content = implode('', $bits);
        }
        $text = trim((string) $content);
        if ($text === '') {
            $text = trim((string) ($message['reasoning_content'] ?? ''));
        }

        return $text;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeLlmRows(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('empty LLM content');
        }
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/s', '', $text) ?? $text;
        $candidates = [$text];
        if (preg_match('/\[[\s\S]*\]/u', $text, $m)) {
            array_unshift($candidates, $m[0]);
        }
        if (preg_match('/\{[\s\S]*\}/u', $text, $m)) {
            $candidates[] = $m[0];
        }
        foreach ($candidates as $raw) {
            $decoded = $this->tryJson($raw);
            if (is_array($decoded)) {
                return $this->rowsFromDecoded($decoded);
            }
        }

        throw new \RuntimeException('invalid JSON from LLM');
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    private function tryJson(string $raw): ?array
    {
        $raw = preg_replace('/,\s*([}\]])/u', '$1', $raw) ?? $raw;
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $fix = preg_replace('/,\s*$/', '', rtrim($raw)) ?? $raw;
        $braces = substr_count($fix, '{') - substr_count($fix, '}');
        $brackets = substr_count($fix, '[') - substr_count($fix, ']');
        if ($braces > 0 || $brackets > 0) {
            $fix .= str_repeat('}', max(0, $braces)).str_repeat(']', max(0, $brackets));
            $decoded = json_decode($fix, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $decoded
     * @return list<array<string, mixed>>
     */
    private function rowsFromDecoded(array $decoded): array
    {
        if (isset($decoded['sku'])) {
            return [$decoded];
        }
        foreach (['rows', 'items', 'data', 'result'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                $decoded = $decoded[$key];
                break;
            }
        }
        $out = [];
        foreach ($decoded as $row) {
            if (is_array($row) && isset($row['sku'])) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private function warnLlm(string $type, string $message, int $n): void
    {
        try {
            Log::warning("Avito attrs DeepSeek [{$type} x{$n}]: {$message}");
        } catch (\Throwable) {
            // artisan не от www-data часто не может писать storage/logs/laravel.log
        }
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

    /**
     * @return Collection<int, StoreSupplierCatalogProduct>
     */
    private function inCategory(string $type): Collection
    {
        $q = StoreSupplierCatalogProduct::query();
        $ids = $this->categoryIds($type);
        if (is_array($ids) && $ids !== []) {
            $q->whereIn('category_external_id', $ids);
        } elseif (! $this->applyNameFallback($q, $type)) {
            return collect();
        }

        return $q->orderBy('sku')->limit(8000)->get();
    }

    private function applyNameFallback($query, string $type): bool
    {
        $includes = $this->search->typeRules()[$type]['name_include'] ?? [];
        if ($includes === []) {
            return false;
        }
        $query->where(function ($w) use ($includes) {
            foreach ($includes as $kw) {
                $w->orWhereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($kw).'%']);
            }
        });

        return true;
    }

    /**
     * @return list<int>|null
     */
    private function categoryIds(string $type): ?array
    {
        try {
            return $this->search->categoryIdsForType($type);
        } catch (\Throwable $e) {
            Log::warning('Avito attrs categories: '.$e->getMessage());

            return null;
        }
    }

    private function rowComplete(string $type, StoreAvitoProductAttr $row): bool
    {
        return $this->isComplete($type, [
            'avito_brand' => $row->avito_brand,
            'avito_model' => $row->avito_model,
            'avito_code' => $row->avito_code,
            'socket' => $row->socket,
            'ddr' => $row->ddr,
            'ram_gb' => $row->ram_gb,
            'wattage' => $row->wattage,
        ]);
    }

    private function isComplete(string $type, array $parsed): bool
    {
        return match ($type) {
            'cpu' => filled($parsed['avito_brand']) && filled($parsed['avito_model']) && filled($parsed['avito_code']) && filled($parsed['socket']),
            'gpu' => filled($parsed['avito_brand']) && filled($parsed['avito_model']) && filled($parsed['avito_code']),
            'ram' => (int) ($parsed['ram_gb'] ?? 0) > 0 && filled($parsed['ddr']),
            'motherboard' => filled($parsed['socket']) && filled($parsed['ddr']) && filled($parsed['avito_brand']),
            'psu' => (int) ($parsed['wattage'] ?? 0) > 0,
            'storage_ssd', 'ssd' => (int) ($parsed['ram_gb'] ?? 0) > 0 || filled($parsed['avito_model']),
            default => filled($parsed['avito_model']),
        };
    }

    private function clamp(string $type, array $parsed): array
    {
        if ($type === 'cpu') {
            $parsed['avito_brand'] = $this->matcher->match('BrandProcessor', (string) ($parsed['avito_brand'] ?? ''))
                ?: $parsed['avito_brand'];
            $parsed['avito_model'] = $this->matcher->match('ModelProcessor', (string) ($parsed['avito_model'] ?? ''), $parsed['avito_brand'] ?? null)
                ?: $parsed['avito_model'];
            $parsed['avito_code'] = $this->matcher->match('CodeProcessor', (string) ($parsed['avito_code'] ?? ''), $parsed['avito_model'] ?? null)
                ?: $parsed['avito_code'];
        }
        if ($type === 'gpu') {
            $hay = trim(($parsed['avito_brand'] ?? '').' '.($parsed['avito_model'] ?? ''));
            $parsed['avito_brand'] = $this->matcher->match('BrandVideocard', $hay) ?: $parsed['avito_brand'];
            $parsed['avito_model'] = $this->matcher->match('ModelVideocard', (string) ($parsed['avito_model'] ?? ''), $parsed['avito_brand'] ?? null)
                ?: $parsed['avito_model'];
            if (! empty($parsed['avito_code'])) {
                $parsed['avito_code'] = $this->matcher->match('CodeVideocard', (string) $parsed['avito_code'], $parsed['avito_model'] ?? null)
                    ?: $parsed['avito_code'];
            }
        }
        if ($type === 'motherboard') {
            $parsed['avito_brand'] = $this->matcher->match('BrandMotherboard', (string) ($parsed['avito_brand'] ?? ''))
                ?: $parsed['avito_brand'];
            $parsed['avito_model'] = $this->matcher->match('ModelMotherboard', (string) ($parsed['avito_model'] ?? ''), $parsed['avito_brand'] ?? null)
                ?: $parsed['avito_model'];
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
