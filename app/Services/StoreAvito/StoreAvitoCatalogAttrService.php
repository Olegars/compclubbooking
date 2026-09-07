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
        $this->excludeWrongComponentNames($q, $type);

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
                if ($type !== 'gpu' || ! $this->gpuSkipShouldRetry($existing, $product)) {
                    continue;
                }
            }
            $pending++;
            $parsed = $this->parser->parse(
                $type,
                (string) $product->name,
                (string) ($product->part ?? ''),
                (string) ($product->vendor ?? ''),
            );
            $parsed = $this->applyStandard($parsed, $type);
            $this->upsert((int) $product->sku, $parsed, 'heuristic');
            $done++;
            $hay = (string) $product->name;
            if ($type === 'gpu' && ($this->parser->isJunkAvitoGpu($hay) || $this->parser->isSkippedAvitoGpu($hay))) {
                continue;
            }
            if (! $this->isComplete($type, $parsed) && $this->isStdType($type)) {
                $needLlm[] = $product;
            }
        }

        if ($useLlm && $needLlm !== [] && $this->isStdType($type) && $this->llmConfigured()) {
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
                'type' => $row['type'] ?? null,
                'standard' => $row['standard'] ?? null,
                'socket' => $row['socket'] ?? null,
                'ddr' => $row['ddr'] ?? null,
                'ram_gb' => isset($row['ram_gb']) ? (int) $row['ram_gb'] : null,
                'wattage' => isset($row['wattage']) ? (int) $row['wattage'] : null,
                'form' => $row['form'] ?? null,
                'avito_brand' => $row['avito_brand'] ?? null,
                'avito_model' => $row['avito_model'] ?? null,
                'avito_code' => $row['avito_code'] ?? $row['standard'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''));
            $merged = $this->groundLlmToName($type, $parsed, $merged, $product);
            $merged = $this->clamp($type, $merged);
            $merged = $this->applyStandard($merged, $type);
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
                'title' => (string) $p->name,
            ];
        }

        $dictHint = match ($type) {
            'cpu' => 'type=cpu. standard — канон как в шаблоне конфигурации, ТОЛЬКО индекс: 7500F (не 7500, не Ryzen 5 7500F), 12400F, 7800X3D. «Ryzen 5 7500F» → standard 7500F, socket AM5. avito_brand Intel|AMD; avito_model Core i5|Ryzen 5. avito_code = standard.',
            'gpu' => 'type=gpu. standard — компактный канон для выборки, слитно латиницей: rtx4060, rtx4060ti, rtx4070super, rtx4070tisuper, rx7900xt. Белый список чипов: 4060/4060ti/4070/4070super/4070ti/4070tisuper/4080/4080super/4090/5050/5060/5060ti/5070/5070ti/5080/5090 и RX 7600/7600xt/7700xt/7800xt/7900gre/7900xt/7900xtx/9060xt/9070/9070xt. «4060» → rtx4060, «4060 Ti/4060ti» → rtx4060ti. Ti и Super только если они есть в заголовке. avito_brand — производитель карты (ZOTAC, Palit, MSI), не NVIDIA. avito_model — из заголовка. avito_code — человекочитаемый чип для XML Avito: RTX 4060 / RTX 4060 Ti. Иначе type=skip и standard=SKIP (RTX 20/30, GTX, A400, L40S, Quadro, принтеры).',
            'ram' => 'type=ram. standard как в шаблоне: «DDR4 32», «DDR5 32», «DDR5 16» (поколение + объём комплекта). Не пиши одно число 32 — без DDR4/DDR5 шаблон не найдёт модуль. ram_gb то же число, ddr DDR4|DDR5, avito_code вида «32 ГБ».',
            'motherboard' => 'type=mb. standard — чипсет как в шаблоне: B550|B650|B650E|B850|B760 (B650M это B650, не путать с B650E). socket AM4|AM5|LGA1700|LGA1851, ddr DDR4|DDR5, avito_brand ASUS|MSI|Gigabyte|ASRock, avito_model — полное имя платы, avito_code = standard.',
            'psu' => 'type=psu. standard — ваттность как в шаблоне: 500, 550, 650, 750, 850. Из «GPS-500A8», «500Вт», «500 W» бери 500. wattage то же число. Не путай с 80 PLUS.',
            'storage_ssd' => 'type=ssd. standard — ТОЛЬКО объём как в шаблоне: 256 или 512. Не пиши «SSD M.2 256 ГБ» и не путай с M.2. «250ГБ/256GB» → 256, «500/512» → 512, «1TB» → 1024. ram_gb то же число.',
            default => 'type по сути товара (cpu|mb|gpu|ram|ssd|psu|skip), standard — канон шаблона.',
        };

        $system = <<<PROMPT
Ты размечаешь комплектующие для сборки ПК. На вход — sku и заголовок товара (title). Артикул и вендор не даём: смотри только заголовок.
Размечай только нужные типы: cpu, gpu, mb, ram, ssd, psu. Кулеры, корпуса, принтеры, расходники и прочее — type=skip.
Сборки потом ищут SKU только по полям type + standard.
Верни ТОЛЬКО JSON-массив, без markdown и без текста вокруг:
[{"sku":1,"type":"cpu|mb|gpu|ram|ssd|psu|skip","standard":"7500F|rtx4060|B650|DDR5 32|256|500","socket":"AM5|AM4|LGA1700|LGA1851|null","ddr":"DDR4|DDR5|null","ram_gb":32,"wattage":650,"form":"atx|matx|itx|null","avito_brand":"...","avito_model":"...","avito_code":"..."}]
Правила: {$dictHint}
type и standard обязательны. avito_code для cpu/mb копируй из standard. Для gpu avito_code — RTX 4060 / RTX 4060 Ti, standard — rtx4060 / rtx4060ti. Не выдумывай поля, которых нет в заголовке — тогда null.
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
                'standard' => $parsed['standard'] ?? null,
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
        $this->excludeWrongComponentNames($q, $type);

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

    private function isStdType(string $type): bool
    {
        return in_array($type, self::STD_TYPES, true);
    }

    /**
     * В категории поставщика бывают чужие позиции — не скармливаем их DeepSeek.
     */
    private function excludeWrongComponentNames($query, string $type): void
    {
        foreach ($this->search->typeRules()[$type]['name_exclude'] ?? [] as $ex) {
            $query->whereRaw('LOWER(name) NOT LIKE ?', ['%'.mb_strtolower((string) $ex).'%']);
        }
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
            'type' => $row->type,
            'standard' => $row->standard,
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
        $code = trim((string) ($parsed['avito_code'] ?? ''));
        $std = trim((string) ($parsed['standard'] ?? ''));
        $canon = ($std !== '' && strcasecmp($std, StoreAvitoCatalogAttrParser::SKIP_GPU) !== 0)
            || ($code !== '' && strcasecmp($code, StoreAvitoCatalogAttrParser::SKIP_GPU) !== 0);

        return match ($type) {
            'cpu' => $canon && filled($parsed['avito_brand']) && filled($parsed['avito_model']) && filled($parsed['socket']),
            'gpu' => $canon && filled($parsed['avito_brand']) && filled($parsed['avito_model']),
            'ram' => ((int) ($parsed['ram_gb'] ?? 0) > 0 || $canon) && filled($parsed['ddr']),
            'motherboard' => $canon && filled($parsed['socket']) && filled($parsed['ddr']) && filled($parsed['avito_brand']),
            'psu' => (int) ($parsed['wattage'] ?? 0) > 0 || $canon,
            'storage_ssd', 'ssd' => $this->parser->parseSsdStandard((string) ($parsed['standard'] ?? '')) !== null
                || (int) ($parsed['ram_gb'] ?? 0) > 0,
            default => filled($parsed['avito_model']),
        };
    }

    /**
     * type + standard — канон шаблона. GPU: standard компактный (rtx4060ti), avito_code — RTX 4060 Ti для XML.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function applyStandard(array $parsed, string $fallbackType): array
    {
        $type = $this->parser->normalizeType((string) ($parsed['type'] ?? $fallbackType));
        if ($type === '') {
            $type = $fallbackType === 'storage_ssd' ? 'ssd' : $fallbackType;
        }
        $parsed['type'] = $type;

        $std = trim((string) ($parsed['standard'] ?? ''));
        if ($std === '' || strcasecmp($std, StoreAvitoCatalogAttrParser::SKIP_GPU) === 0) {
            $parsed['standard'] = $this->parser->deriveStandard($parsed);
            $std = (string) ($parsed['standard'] ?? '');
        } else {
            $parsed['standard'] = $std;
        }

        if ($std !== '' && strcasecmp($std, StoreAvitoCatalogAttrParser::SKIP_GPU) !== 0) {
            if (in_array($type, ['cpu', 'motherboard'], true)) {
                $parsed['avito_code'] = $std;
            }
            if ($type === 'gpu') {
                $raw = $std !== '' ? $std : (string) ($parsed['avito_code'] ?? '');
                $compact = $this->parser->gpuStandard($raw) ?: $this->parser->gpuStandard((string) ($parsed['avito_code'] ?? ''));
                if ($compact !== null) {
                    $parsed['standard'] = $compact;
                    $parsed['avito_code'] = $this->parser->gpuStandardPretty($raw)
                        ?: $this->parser->gpuStandardPretty((string) ($parsed['avito_code'] ?? ''));
                }
            }
            if ($type === 'ram') {
                $ramStd = $this->parser->parseRamStandard($std)
                    ?: $this->parser->parseRamStandard(trim((string) ($parsed['ddr'] ?? '').' '.(string) ($parsed['ram_gb'] ?? $std)));
                if ($ramStd !== null) {
                    $parsed['standard'] = $ramStd['standard'];
                    $parsed['ddr'] = $ramStd['ddr'];
                    $parsed['ram_gb'] = $ramStd['ram_gb'];
                    $parsed['avito_code'] = AvitoPcXmlDict::ramSizeForGb($ramStd['ram_gb']);
                }
            }
            if ($type === 'ssd') {
                $ssdStd = $this->parser->parseSsdStandard($std)
                    ?: $this->parser->parseSsdStandard((string) ($parsed['ram_gb'] ?? ''));
                if ($ssdStd !== null) {
                    $parsed['standard'] = $ssdStd;
                    $parsed['ram_gb'] = (int) $ssdStd;
                }
            }
            if ($type === 'psu' && (int) $std > 0) {
                $parsed['wattage'] = (int) $std;
            }
        }

        if (($parsed['avito_code'] ?? '') === StoreAvitoCatalogAttrParser::SKIP_GPU) {
            $parsed['standard'] = null;
        }

        return $parsed;
    }

    /**
     * DeepSeek пишет type=gpu и compact standard (rtx4060ti). Чип из LLM не подменяем парсером имени.
     * Хлам (A400, барабан, 3060) — skip. Если LLM ничего не дал — эвристика по названию.
     *
     * @param  array<string, mixed>  $heuristic
     * @param  array<string, mixed>  $merged
     * @return array<string, mixed>
     */
    private function groundLlmToName(string $type, array $heuristic, array $merged, StoreSupplierCatalogProduct $product): array
    {
        $llmType = $this->parser->normalizeType((string) ($merged['type'] ?? $type));
        if ($llmType !== '') {
            $merged['type'] = $llmType;
        }
        if ($type !== 'gpu' && $llmType !== 'gpu') {
            return $merged;
        }
        $hay = (string) $product->name;
        if ($this->parser->isJunkAvitoGpu($hay) || $this->parser->isSkippedAvitoGpu($hay) || $this->parser->isWorkstationGpu($hay)) {
            $merged['type'] = 'skip';
            $merged['standard'] = null;
            $merged['avito_code'] = StoreAvitoCatalogAttrParser::SKIP_GPU;
            $merged['avito_model'] = $heuristic['avito_model'] ?? ($merged['avito_model'] ?? null);

            return $merged;
        }
        $compact = $this->parser->gpuStandard((string) ($merged['standard'] ?? ''))
            ?: $this->parser->gpuStandard((string) ($merged['avito_code'] ?? ''));
        if ($compact !== null) {
            $merged['type'] = 'gpu';
            $merged['standard'] = $compact;
            $merged['avito_code'] = $this->parser->gpuStandardPretty($compact);

            return $merged;
        }
        $fromName = $this->parser->gpuStandard((string) ($this->parser->allowedAvitoGpuChip($hay) ?? ''));
        if ($fromName !== null) {
            $merged['type'] = 'gpu';
            $merged['standard'] = $fromName;
            $merged['avito_code'] = $this->parser->gpuStandardPretty($fromName);

            return $merged;
        }
        $merged['type'] = 'skip';
        $merged['standard'] = null;
        $merged['avito_code'] = StoreAvitoCatalogAttrParser::SKIP_GPU;

        return $merged;
    }

    private function gpuSkipShouldRetry(StoreAvitoProductAttr $row, StoreSupplierCatalogProduct $product): bool
    {
        if (($row->avito_code ?? '') !== StoreAvitoCatalogAttrParser::SKIP_GPU) {
            return false;
        }
        $hay = (string) $product->name;

        return $this->parser->looksLikeDesktopGpu($hay)
            && ! $this->parser->isJunkAvitoGpu($hay)
            && ! $this->parser->isSkippedAvitoGpu($hay)
            && ! $this->parser->isWorkstationGpu($hay);
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
            if (($parsed['avito_code'] ?? '') === StoreAvitoCatalogAttrParser::SKIP_GPU) {
                return $parsed;
            }
            $modelHay = trim(($parsed['avito_model'] ?? '').' '.($parsed['avito_code'] ?? ''));
            if (! $this->parser->isSkippedAvitoGpu($modelHay)) {
                $hay = trim(($parsed['avito_brand'] ?? '').' '.($parsed['avito_model'] ?? ''));
                $parsed['avito_brand'] = $this->matcher->match('BrandVideocard', $hay) ?: $parsed['avito_brand'];
                $parsed['avito_model'] = $this->matcher->match('ModelVideocard', (string) ($parsed['avito_model'] ?? ''), $parsed['avito_brand'] ?? null)
                    ?: $parsed['avito_model'];
                if (! empty($parsed['avito_code'])) {
                    $parsed['avito_code'] = $this->matcher->match('CodeVideocard', (string) $parsed['avito_code'], $parsed['avito_model'] ?? null)
                        ?: $parsed['avito_code'];
                }
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
