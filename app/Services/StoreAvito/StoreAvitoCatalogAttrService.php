<?php

namespace App\Services\StoreAvito;

use App\Models\AiAssistantSetting;
use App\Models\StoreAvitoPart;
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

    /** @var array<string, list<string>> */
    private array $templateCanonCache = [];

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
            if ($existing && $existing->source === 'deepseek' && $this->isStdType($type)) {
                continue;
            }
            if ($existing && $this->rowComplete($type, $existing) && ! $this->isStdType($type)) {
                continue;
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
            if ($type === 'gpu' && ($this->parser->isJunkAvitoGpu($hay) || $this->parser->isWorkstationGpu($hay))) {
                continue;
            }
            if ($this->isStdType($type)) {
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
            $kind = $this->parser->normalizeType($type);
            $llmStd = trim((string) ($row['standard'] ?? $row['chipset'] ?? $row['chip'] ?? ''));
            $llmCode = trim((string) ($row['avito_code'] ?? ''));
            if (strcasecmp($llmStd, StoreAvitoCatalogAttrParser::SKIP_GPU) === 0 || $this->isOversizedCanon($llmStd)) {
                $llmStd = '';
            }
            if (strcasecmp($llmCode, StoreAvitoCatalogAttrParser::SKIP_GPU) === 0 || $this->isOversizedCanon($llmCode)) {
                $llmCode = '';
            }
            $merged = array_merge($parsed, array_filter([
                'socket' => $row['socket'] ?? null,
                'ddr' => $row['ddr'] ?? null,
                'ram_gb' => isset($row['ram_gb']) ? (int) $row['ram_gb'] : null,
                'wattage' => isset($row['wattage']) ? (int) $row['wattage'] : null,
                'form' => $row['form'] ?? null,
                'avito_brand' => $row['avito_brand'] ?? null,
                'avito_model' => $row['avito_model'] ?? null,
                'avito_code' => $llmCode !== '' ? $llmCode : null,
            ], fn ($v) => $v !== null && $v !== ''));
            $merged['type'] = $kind !== '' ? $kind : $this->parser->normalizeType($type);
            $merged['avito_code'] = $llmCode !== '' ? $llmCode : null;
            if ($llmStd !== '') {
                $merged['standard'] = $llmStd;
            } elseif ($llmCode !== '') {
                $merged['standard'] = $llmCode;
            } elseif ($kind === 'psu' && isset($row['wattage'])) {
                $merged['standard'] = (string) (int) $row['wattage'];
            } else {
                $merged['standard'] = null;
            }
            $merged = $this->groundLlmToName($type, $parsed, $merged, $product);
            $merged = $this->clamp($type, $merged);
            $merged = $this->applyStandard($merged, $type, fromLlm: true);
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
        $kind = $this->parser->normalizeType($type);
        $kindLabel = match ($kind) {
            'cpu' => 'процессоры',
            'gpu' => 'видеокарты',
            'motherboard' => 'материнские платы',
            'ram' => 'оперативная память',
            'ssd' => 'накопители SSD',
            'psu' => 'блоки питания',
            default => $kind,
        };
        $task = match ($kind) {
            'cpu' => 'Это процессор. Выдай индекс как в шаблонах конфигураций: 7500F, 12400F, 7800X3D. Пример: «Процессор AMD Ryzen 5 7500F Soc-AM5...» → 7500F. Не «Ryzen 5 7500F» и не «7500». Ноутбук/EPYC/Xeon — standard=null.',
            'gpu' => 'Это видеокарта. Выдай какой там процессор в таком виде, слитно без пробелов: rtx4060, rtx4060ti, rtx5070. Пример: «Видеокарта Palit GeForce RTX 5070 INFINITY 3, 12 GB GDDR7...» → rtx5070. Ti/Super только если есть в заголовке. Не из списка шаблонов (A400, L40S, GTX, RTX 20/30, принтер) — standard=null.',
            'motherboard' => 'Это материнская плата. Выдай чипсет как в шаблонах: Z890, B650, B650E, B760. Пример: «Материнская плата GIGABYTE Z890M AORUS ELITE WIFI7 ICE, LGA1851, Intel Z890...» → Z890. Z890M это Z890, B650M это B650, не путать с B650E. Не пиши Intel/AMD перед чипсетом. Если чипсет не из списка — standard=null.',
            'ram' => 'Это оперативная память. Выдай поколение и объём комплекта как в шаблонах: DDR4 32, DDR5 32, DDR5 16. Пример: «Kingston Fury Beast DDR5 32GB...» → DDR5 32. Не одно число 32. Другой объём — standard=null.',
            'ssd' => 'Это SSD. Выдай только объём как в шаблонах: 256 или 512. Пример: «Kingston NV2 256GB» → 256. 1TB и серверные 6.4TB — standard=null.',
            'psu' => 'Это блок питания. Выдай ватты как в шаблонах: 500, 650, 750. Пример: «Блок питания Chieftec GPS-500A8» → 500. Бытовая техника и ватты вне списка — standard=null.',
            default => 'Верни короткий канон в standard — только из списка шаблонов.',
        };
        $example = match ($kind) {
            'cpu' => '7500F',
            'gpu' => 'rtx5070',
            'motherboard' => 'Z890',
            'ram' => 'DDR5 32',
            'ssd' => '256',
            'psu' => '500',
            default => '',
        };
        $templates = implode(', ', $this->templateCanons($kind));

        $payload = [
            'kind' => $kind,
            'items' => [],
        ];
        foreach ($chunk as $p) {
            $payload['items'][] = [
                'sku' => (int) $p->sku,
                'title' => (string) $p->name,
            ];
        }

        $system = <<<PROMPT
Это {$kindLabel}. Тип из категории каталога уже известен ({$kind}) — type не определяй.
На вход sku и заголовок (title).
Задача: {$task}
standard — СТРОГО одно значение из шаблонов конфигураций (как в сборках):
{$templates}
Если в заголовке нет ни одного из списка — standard=null. Не подбирай соседний индекс.
Верни ТОЛЬКО JSON-массив:
[{"sku":1,"standard":"{$example}"}]
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
        $parsed = $this->fitForDb($parsed);
        try {
            StoreAvitoProductAttr::query()->updateOrCreate(
                ['sku' => $sku],
                [
                    'type' => $parsed['type'] ?? 'skip',
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
        } catch (\Throwable $e) {
            $this->warnLlm((string) ($parsed['type'] ?? ''), 'upsert sku '.$sku.': '.$e->getMessage(), 1);
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function fitForDb(array $parsed): array
    {
        $type = $this->parser->normalizeType((string) ($parsed['type'] ?? 'skip'));
        $allowed = ['cpu', 'gpu', 'motherboard', 'ram', 'ssd', 'psu', 'skip', 'cooler', 'case', 'other'];
        if ($type === '' || ! in_array($type, $allowed, true) || mb_strlen($type) > 32) {
            $type = 'skip';
        }
        $parsed['type'] = $type;

        $std = trim((string) ($parsed['standard'] ?? ''));
        if ($type === 'skip' || $std === '' || strcasecmp($std, StoreAvitoCatalogAttrParser::SKIP_GPU) === 0 || $this->isOversizedCanon($std)) {
            $parsed['standard'] = null;
        } else {
            $parsed['standard'] = $std;
        }

        $parsed['socket'] = $this->clipDb($parsed['socket'] ?? null, 32);
        $parsed['ddr'] = $this->clipDb($parsed['ddr'] ?? null, 16);
        $parsed['form'] = $this->clipDb($parsed['form'] ?? null, 16);
        $parsed['avito_brand'] = $this->clipDb($parsed['avito_brand'] ?? null, 255);
        $parsed['avito_model'] = $this->clipDb($parsed['avito_model'] ?? null, 255);
        $parsed['avito_code'] = $this->clipDb($parsed['avito_code'] ?? null, 255);

        return $parsed;
    }

    private function isOversizedCanon(?string $raw): bool
    {
        $raw = trim((string) $raw);

        return $raw !== '' && mb_strlen($raw) > 64;
    }

    private function clipDb(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max);
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
     * type + standard — канон шаблона конфигурации. GPU: standard компактный (rtx4060ti), avito_code — RTX 4060 Ti для XML.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function applyStandard(array $parsed, string $fallbackType, bool $fromLlm = false): array
    {
        $type = $this->parser->normalizeType((string) ($parsed['type'] ?? $fallbackType));
        if ($type === '') {
            $type = $fallbackType === 'storage_ssd' ? 'ssd' : $fallbackType;
        }
        $parsed['type'] = $type;

        if ($type === 'skip') {
            $parsed['standard'] = null;

            return $parsed;
        }

        $allowed = $this->templateCanons($type);
        $std = $this->parser->pickTemplateCanon($type, (string) ($parsed['standard'] ?? ''), $allowed);
        if ($std === null && ! $fromLlm) {
            $std = $this->parser->pickTemplateCanon(
                $type,
                (string) ($this->parser->deriveStandard($parsed) ?? ''),
                $allowed,
            );
        }
        $parsed['standard'] = $std;

        if ($std !== null) {
            if (in_array($type, ['cpu', 'motherboard'], true)) {
                $parsed['avito_code'] = $std;
            }
            if ($type === 'gpu') {
                $parsed['standard'] = $std;
                $parsed['avito_code'] = $this->parser->gpuStandardPretty($std) ?: $parsed['avito_code'] ?? null;
            }
            if ($type === 'ram') {
                $ramStd = $this->parser->parseRamStandard($std);
                if ($ramStd !== null) {
                    $parsed['standard'] = $ramStd['standard'];
                    $parsed['ddr'] = $ramStd['ddr'];
                    $parsed['ram_gb'] = $ramStd['ram_gb'];
                    $parsed['avito_code'] = AvitoPcXmlDict::ramSizeForGb($ramStd['ram_gb']);
                }
            }
            if ($type === 'ssd') {
                $parsed['ram_gb'] = (int) $std;
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
     * Каноны включённых шаблонов комплектующих. Если частей ещё нет — список сидера.
     *
     * @return list<string>
     */
    public function templateCanons(string $type): array
    {
        $type = $this->parser->normalizeType($type);
        if (isset($this->templateCanonCache[$type])) {
            return $this->templateCanonCache[$type];
        }
        $partType = $type === 'ssd' ? 'ssd' : $type;
        $found = [];
        try {
            $parts = StoreAvitoPart::query()
                ->where('type', $partType)
                ->where('enabled', true)
                ->get();
            foreach ($parts as $part) {
                $raw = match ($type) {
                    'gpu' => (string) ($part->avito_code ?? ''),
                    'ram' => (string) ($this->parser->ramStandard($part->ddr, $part->ram_gb) ?? ''),
                    'ssd' => (int) $part->capacity_gb > 0 ? (string) (int) $part->capacity_gb : '',
                    'psu' => (int) $part->wattage > 0 ? (string) (int) $part->wattage : '',
                    default => (string) ($part->avito_code ?? ''),
                };
                $canon = $this->parser->canonStandard($type, $raw);
                if ($canon !== null) {
                    $found[$canon] = true;
                }
            }
        } catch (\Throwable) {
            $found = [];
        }
        $list = $found !== [] ? array_keys($found) : $this->parser->defaultTemplateCanons($type);
        $this->templateCanonCache[$type] = $list;

        return $list;
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
        $kind = $this->parser->normalizeType((string) ($merged['type'] ?? $type));
        $merged['type'] = $kind !== '' ? $kind : $this->parser->normalizeType($type);
        if ($type !== 'gpu' && $merged['type'] !== 'gpu') {
            return $merged;
        }
        $hay = (string) $product->name;
        if ($this->parser->isJunkAvitoGpu($hay) || $this->parser->isWorkstationGpu($hay)) {
            $merged['type'] = 'gpu';
            $merged['standard'] = null;
            $merged['avito_code'] = null;
            $merged['avito_model'] = $heuristic['avito_model'] ?? ($merged['avito_model'] ?? null);

            return $merged;
        }
        $compact = $this->parser->gpuStandard((string) ($merged['standard'] ?? ''));
        $merged['type'] = 'gpu';
        if ($compact !== null) {
            $merged['standard'] = $compact;
            $merged['avito_code'] = $this->parser->gpuStandardPretty($compact);

            return $merged;
        }
        $merged['standard'] = null;
        $merged['avito_code'] = null;

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
