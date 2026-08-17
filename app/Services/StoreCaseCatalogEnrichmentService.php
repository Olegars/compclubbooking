<?php

namespace App\Services;

use App\Models\AiAssistantSetting;
use App\Models\StoreSupplierCatalogProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Классификация корпусов (цвет / стекло / форм-фактор) через DeepSeek.
 * Результат кэшируется в store_supplier_catalog_products.case_*.
 * Ключ/URL/модель — те же, что в админке AI (AiAssistantSetting) с fallback на .env.
 */
class StoreCaseCatalogEnrichmentService
{
    private const BATCH = 35;

    public function isConfigured(): bool
    {
        return AiAssistantSetting::forClub(null)->resolvedLlmApiKey() !== '';
    }

    /**
     * Пробная классификация одного названия (админка «Проверить LLM»).
     *
     * @return array{
     *   ok: true,
     *   name: string,
     *   part: string,
     *   raw: array<string, mixed>|null,
     *   color: ?string,
     *   glass: ?string,
     *   form: ?string,
     *   labels: array{color: string, glass: string, form: string},
     *   model: string,
     *   base_url: string,
     *   reply: string
     * }
     */
    public function classifyProbe(string $name, string $part = ''): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Укажите название корпуса.');
        }
        if (! $this->isConfigured()) {
            throw new \RuntimeException('LLM API-ключ не задан (админка или .env).');
        }

        $settings = AiAssistantSetting::forClub(null);
        $lines = [['sku' => 1, 'name' => $name, 'part' => trim($part)]];
        $parsed = $this->askJson($this->systemPrompt(), $this->userPrompt($lines));
        $raw = null;
        foreach ($parsed as $row) {
            if (is_array($row) && (int) ($row['sku'] ?? 0) === 1) {
                $raw = $row;
                break;
            }
        }
        if ($raw === null && isset($parsed[0]) && is_array($parsed[0])) {
            $raw = $parsed[0];
        }

        $color = $this->normalizeColor($raw['color'] ?? null);
        $glass = $this->normalizeGlass($raw['glass'] ?? null);
        $form = $this->normalizeForm($raw['form'] ?? null);

        $labels = [
            'color' => match ($color) {
                'white' => 'белый',
                'black' => 'чёрный',
                'other' => 'другой',
                default => '— (не распознан: '.($raw['color'] ?? '?').')',
            },
            'glass' => match ($glass) {
                'none' => 'без стекла',
                'side' => 'стекло сбоку',
                'front_side' => 'стекло спереди и сбоку',
                default => '— (не распознан: '.($raw['glass'] ?? '?').')',
            },
            'form' => match ($form) {
                'atx' => 'ATX',
                'matx' => 'mATX',
                'itx' => 'ITX',
                'eatx' => 'E-ATX',
                'other' => 'другой',
                default => '— (не распознан: '.($raw['form'] ?? '?').')',
            },
        ];

        $reply = 'цвет: '.$labels['color']
            .' · стекло: '.$labels['glass']
            .' · форм-фактор: '.$labels['form'];

        return [
            'ok' => true,
            'name' => $name,
            'part' => trim($part),
            'raw' => $raw,
            'color' => $color,
            'glass' => $glass,
            'form' => $form,
            'labels' => $labels,
            'model' => $settings->resolvedLlmModel(),
            'base_url' => $settings->resolvedLlmBaseUrl(),
            'reply' => $reply,
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Ты классификатор корпусов ПК по названию из прайса.
Верни ТОЛЬКО JSON-массив объектов без markdown:
[{"sku":123,"color":"white|black|other","glass":"none|side|front_side","form":"atx|matx|itx|eatx|other"}]

Правила:
- color: white/black если явно указан цвет корпуса; иначе other.
- glass:
  - side = только боковое стекло/окно (в т.ч. «боковое окно (панорама)», tempered side).
  - front_side = стекло И спереди, И сбоку (передняя стеклянная панель + бок, dual/full glass, «передняя и боковая»).
  - none = нет стекла / только сетка / неясно.
  Важно: одно боковое «панорамное» окно = side, НЕ front_side.
- form: atx только полноценный ATX (не mATX/MicroATX/Mini-ITX). mATX→matx, ITX→itx, E-ATX→eatx.
PROMPT;
    }

    /**
     * @param  list<array{sku:int,name:string,part:string}>  $lines
     */
    private function userPrompt(array $lines): string
    {
        return "Классифицируй корпуса:\n".json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Прогнать корпуса каталога.
     * По умолчанию — только с пустой разметкой (case_attrs_at IS NULL).
     * DeepSeek/эвристика для уже размеченных не вызываются.
     *
     * @return array{total: int, classified: int, pending_before: int}
     */
    public function classifyAllCases(bool $force = false): array
    {
        $search = app(StoreSupplierCatalogSearchService::class);
        $categoryIds = $search->categoryIdsForType('case');

        $base = StoreSupplierCatalogProduct::query();
        if (is_array($categoryIds) && $categoryIds !== []) {
            $base->whereIn('category_external_id', $categoryIds);
        } else {
            $base->where(function ($q) {
                $q->where('name', 'ilike', '%корпус%')
                    ->orWhere('name', 'ilike', '%chassis%')
                    ->orWhere('name', 'ilike', '% case %');
            });
        }

        $total = (clone $base)->count();

        if ($force) {
            (clone $base)->update([
                'case_color' => null,
                'case_glass' => null,
                'case_form' => null,
                'case_attrs_at' => null,
            ]);
        }

        $pendingSkus = (clone $base)
            ->whereNull('case_attrs_at')
            ->orderBy('sku')
            ->pluck('sku')
            ->map(fn ($s) => (int) $s)
            ->all();

        $pendingBefore = count($pendingSkus);
        $classified = $pendingSkus === [] ? 0 : $this->ensureClassified($pendingSkus);

        return [
            'total' => $total,
            'pending_before' => $pendingBefore,
            'classified' => $classified,
        ];
    }

    /**
     * Разметить только sku без case_attrs_at. Уже заполненные пропускаются.
     *
     * @param  list<int>  $skus
     */
    public function ensureClassified(array $skus): int
    {
        $skus = array_values(array_unique(array_map('intval', $skus)));
        $skus = array_values(array_filter($skus, fn ($s) => $s > 0));
        if ($skus === []) {
            return 0;
        }

        $pending = StoreSupplierCatalogProduct::query()
            ->whereIn('sku', $skus)
            ->whereNull('case_attrs_at')
            ->orderBy('sku')
            ->get(['sku', 'name', 'part']);

        if ($pending->isEmpty()) {
            return 0;
        }

        if (! $this->isConfigured()) {
            Log::info('Case attrs: LLM API-ключ не задан (админка/.env), разметка пропущена ('.$pending->count().' корпусов).');

            return 0;
        }

        $done = 0;
        foreach ($pending->chunk(self::BATCH) as $chunk) {
            $done += $this->classifyChunk($chunk);
        }

        return $done;
    }

    /**
     * @param  Collection<int, StoreSupplierCatalogProduct>  $chunk
     */
    private function classifyChunk(Collection $chunk): int
    {
        $lines = [];
        foreach ($chunk as $p) {
            $lines[] = [
                'sku' => (int) $p->sku,
                'name' => (string) $p->name,
                'part' => (string) ($p->part ?? ''),
            ];
        }

        $system = $this->systemPrompt();
        $user = $this->userPrompt($lines);

        try {
            $parsed = $this->askJson($system, $user);
        } catch (\Throwable $e) {
            Log::warning('Case attrs DeepSeek: '.$e->getMessage());

            return 0;
        }

        $bySku = [];
        foreach ($parsed as $row) {
            if (! is_array($row) || empty($row['sku'])) {
                continue;
            }
            $bySku[(int) $row['sku']] = $row;
        }

        $now = now();
        $updated = 0;
        foreach ($chunk as $p) {
            $row = $bySku[(int) $p->sku] ?? null;
            if ($row === null) {
                continue;
            }
            $color = $this->normalizeColor($row['color'] ?? null);
            $glass = $this->normalizeGlass($row['glass'] ?? null);
            $form = $this->normalizeForm($row['form'] ?? null);
            if ($color === null || $glass === null || $form === null) {
                continue;
            }

            StoreSupplierCatalogProduct::query()->where('sku', $p->sku)->update([
                'case_color' => $color,
                'case_glass' => $glass,
                'case_form' => $form,
                'case_attrs_at' => $now,
                'updated_at' => $now,
            ]);
            $updated++;
        }

        return $updated;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function askJson(string $system, string $user): array
    {
        $settings = AiAssistantSetting::forClub(null);
        $key = $settings->resolvedLlmApiKey();
        $base = $settings->resolvedLlmBaseUrl();
        $model = $settings->resolvedLlmModel();
        $timeout = (float) config('ai_assistant.http_timeout', 60);

        $payload = [
            'model' => $model,
            'temperature' => 0.1,
            'max_tokens' => 3500,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];
        // V4 thinking по умолчанию съедает бюджет токенов → content пустой, в БД ничего не пишется
        if (str_contains(strtolower($model), 'deepseek')) {
            $payload['thinking'] = ['type' => 'disabled'];
        }

        $response = Http::timeout(max(60.0, $timeout))
            ->withToken($key)
            ->acceptJson()
            ->post($base.'/chat/completions', $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('HTTP '.$response->status().' '.$response->body());
        }

        $message = data_get($response->json(), 'choices.0.message', []);
        $text = trim((string) (is_array($message) ? ($message['content'] ?? '') : ''));
        if ($text === '' && is_array($message)) {
            $text = trim((string) ($message['reasoning_content'] ?? ''));
        }
        if ($text === '') {
            throw new \RuntimeException('empty LLM content (model='.$model.', base='.$base.')');
        }

        if (preg_match('/\[[\s\S]*\]/u', $text, $m)) {
            $text = $m[0];
        }

        $json = json_decode($text, true);
        if (! is_array($json)) {
            throw new \RuntimeException('invalid JSON from LLM');
        }

        return array_values($json);
    }

    private function normalizeColor(mixed $v): ?string
    {
        $v = mb_strtolower(trim((string) $v));

        return match ($v) {
            'white', 'белый', 'белая', 'белое' => 'white',
            'black', 'черный', 'чёрный', 'черная', 'чёрная' => 'black',
            'other', 'другой', 'иной' => 'other',
            default => null,
        };
    }

    private function normalizeGlass(mixed $v): ?string
    {
        $v = mb_strtolower(trim((string) $v));

        return match ($v) {
            'none', 'нет', 'no' => 'none',
            'side', 'бок', 'боковое' => 'side',
            'front_side', 'front-side', 'frontside', 'спереди', 'front' => 'front_side',
            default => null,
        };
    }

    private function normalizeForm(mixed $v): ?string
    {
        $v = mb_strtolower(trim((string) $v));
        $v = str_replace(['-', '_', ' '], '', $v);

        return match ($v) {
            'atx' => 'atx',
            'matx', 'microatx' => 'matx',
            'itx', 'miniitx' => 'itx',
            'eatx', 'extatx' => 'eatx',
            'other' => 'other',
            default => null,
        };
    }
}
