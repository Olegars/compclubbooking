<?php

namespace App\Services;

use App\Models\StoreSupplierCatalogCategory;
use App\Models\StoreSupplierCatalogProduct;
use Illuminate\Support\Facades\Cache;

class StoreSupplierCatalogSearchService
{
    private const CACHE_PREFIX = 'store.quickfox.cat_ids.v5.';

    /**
     * Правила типа: категории ITP + жёсткий фильтр по названию товара.
     *
     * @return array<string, array{cat: list<string>, cat_exclude?: list<string>, name_exclude?: list<string>, name_include?: list<string>}>
     */
    public function typeRules(): array
    {
        $custom = config('store.quickfox.type_category_keywords');
        if (is_array($custom) && $custom !== [] && isset($custom['cpu']) && is_array($custom['cpu']) && array_is_list($custom['cpu'])) {
            // Старый формат: type => [keywords...] — только категории
            $rules = [];
            foreach ($custom as $type => $keywords) {
                $rules[$type] = ['cat' => array_values($keywords)];
            }

            return $rules;
        }

        return [
            'cpu' => [
                'cat' => ['процессор', 'cpu', 'processor'],
                'name_exclude' => ['материнск', 'видеокарт', 'оперативн', 'память ddr', 'блок питания'],
                'name_include' => ['процессор', 'cpu', 'core i', 'ryzen', 'intel', 'amd'],
            ],
            'motherboard' => [
                'cat' => ['материнск', 'motherboard', 'системная плата'],
                'name_exclude' => ['процессор', 'видеокарт', 'оперативн память', 'блок питания'],
                'name_include' => ['материнск', 'motherboard', 'плата'],
            ],
            'ram' => [
                'cat' => ['оперативн', 'озу', 'dimm', 'so-dimm', 'модул памяти', 'модули памяти', 'модуль памяти'],
                'cat_exclude' => ['материнск', 'видеокарт', 'накопител', 'ssd', 'hdd', 'процессор'],
                'name_exclude' => ['материнск', 'motherboard', 'системная плата', 'видеокарт', 'ssd', 'nvme', 'процессор', 'блок питания'],
                'name_include' => ['ddr', 'dimm', 'so-dimm', 'оперативн', 'озу', 'kingston', 'corsair', 'crucial', 'adata', 'g.skill', 'patriot', 'netac'],
            ],
            'gpu' => [
                'cat' => ['видеокарт', 'видеоадаптер', 'graphics', 'gpu'],
                'name_exclude' => ['материнск', 'процессор', 'оперативн'],
                'name_include' => ['видеокарт', 'geforce', 'radeon', 'rtx', 'gtx', 'rx '],
            ],
            'storage_ssd' => [
                'cat' => ['ssd', 'nvme', 'твердотельн'],
                'cat_exclude' => ['hdd', 'жестк', 'жёстк'],
                'name_exclude' => ['hdd', 'жестк', 'жёстк', 'материнск', 'видеокарт'],
                'name_include' => ['ssd', 'nvme', 'm.2', 'твердотельн'],
            ],
            'storage_hdd' => [
                'cat' => ['hdd', 'жестк', 'жёстк', 'винчестер'],
                'name_exclude' => ['ssd', 'nvme', 'материнск'],
                'name_include' => ['hdd', 'жестк', 'жёстк', 'винчестер'],
            ],
            'psu' => [
                'cat' => ['блок питания', 'power supply'],
                'cat_exclude' => ['бытов', 'микроволн', 'аэрогрил', 'чайник', 'утюг', 'фен'],
                'name_exclude' => [
                    'микроволн', 'аэрогрил', 'гриль', 'духовк', 'печь', 'чайник', 'утюг', 'фен',
                    'пылесос', 'бытов', 'кофевар', 'мультивар', 'обогревател', 'конвектор',
                    'вытяжка', 'стиральн', 'посудомо', 'холодильн',
                ],
                // Нельзя включать голое «вт»/«watt» — иначе микроволновки и грили
                'name_include' => ['блок питания', 'бп ', ' psu', 'psu ', 'power supply', 'atx'],
            ],
            'case' => [
                'cat' => ['корпус'],
                'cat_exclude' => ['блок питания'],
                'name_include' => ['корпус'],
            ],
            'cooler' => [
                'cat' => ['кулер', 'охлажден', 'cooler', 'термопаст'],
                'cat_exclude' => ['корпус', 'бытов'],
                'name_exclude' => ['корпус', 'блок питания', 'материнск', 'видеокарт', 'бытов', 'gpu'],
                'name_include' => ['кулер', 'охлажд', 'cooler', 'термопаст', 'водян', 'процессор', 'cpu'],
            ],
            'fan' => [
                // Только вентиляторы под охлаждение CPU, диаметр 120 мм (см. forcedFilters)
                'cat' => ['вентилятор', 'кулер'],
                'cat_exclude' => ['бытов', 'видеокарт', 'корпус системн'],
                'name_exclude' => [
                    'видеокарт', 'gpu', 'бытов', 'микроволн', 'аэрогрил',
                    '140мм', '140 mm', '140mm', '200мм', '200 mm', '200mm',
                    '80мм', '80 mm', '80mm', '92мм', '92 mm', '92mm',
                ],
                'name_include' => ['вентилятор', 'fan', 'cooler', 'кулер'],
            ],
            'network' => [
                'cat' => ['сетев', 'wifi', 'wi-fi', 'адаптер беспровод'],
                'name_include' => ['сетев', 'wifi', 'wi-fi', 'ethernet'],
            ],
            'os' => [
                'cat' => ['операционн', 'windows', 'лицензи'],
                'name_include' => ['windows', 'лицензи', 'ос '],
            ],
        ];
    }

    /** @deprecated use typeRules() */
    public function typeKeywords(): array
    {
        $out = [];
        foreach ($this->typeRules() as $type => $rule) {
            $out[$type] = $rule['cat'] ?? [];
        }

        return $out;
    }

    /**
     * @return list<array{sku:int,name:string,part:?string,vendor:?string,rrp:mixed,price:?float,stock_qty:?int,in_stock:bool,category_external_id:?int,score:int,category_name:?string}>
     */
    public function search(string $q, ?string $type = null, ?int $categoryId = null, int $limit = 40, bool $inStockOnly = true): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }

        $type = $type ? strtolower(trim($type)) : null;
        $rule = $type ? ($this->typeRules()[$type] ?? null) : null;

        $categoryIds = null;
        if ($categoryId) {
            $categoryIds = $this->categorySubtreeIds($categoryId);
        } elseif ($type) {
            $categoryIds = $this->categoryIdsForType($type);
            if ($categoryIds === []) {
                $categoryIds = null; // режем по name_include/exclude ниже
            }
        }

        $qLower = mb_strtolower($q);
        $tokens = preg_split('/\s+/u', $qLower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_filter($tokens, fn ($t) => mb_strlen($t) >= 2));
        if ($tokens === []) {
            return [];
        }
        $isNumericSku = count($tokens) === 1 && (bool) preg_match('/^\d{4,}$/', $tokens[0]);

        $caseFilters = [];
        if ($type === 'case') {
            [$caseFilters, $tokens] = $this->splitCaseFilterTokens($tokens);
            // Только чипы корпуса (цвет/стекло/ATX) без текста — ок
            if ($tokens === [] && $caseFilters === []) {
                return [];
            }
        }

        $builder = StoreSupplierCatalogProduct::query()
            ->when($inStockOnly, fn ($query) => $query->whereNotNull('price'))
            ->when($categoryIds !== null && $categoryIds !== [], function ($query) use ($categoryIds) {
                $query->whereIn('category_external_id', $categoryIds);
            });

        // Все слова запроса должны встретиться; для объёмов/Вт — синонимы + границы числа (700 ≠ 1700)
        foreach ($tokens as $token) {
            if ($this->isAtxFormFactorToken($token)) {
                // ATX ≠ mATX / MicroATX / Mini-ATX
                $builder->where(function ($query) {
                    $pos = '(^|[^a-zа-яё0-9])atx([^a-zа-яё0-9]|$)';
                    $neg = '(^|[^a-zа-яё0-9])(m|micro|mini)\\s*-?\\s*atx([^a-zа-яё0-9]|$)';
                    $query->where(function ($q) use ($pos) {
                        $q->whereRaw('name ~* ?', [$pos])
                            ->orWhereRaw('part ~* ?', [$pos]);
                    })->whereRaw(
                        "coalesce(name, '') || ' ' || coalesce(part, '') !~* ?",
                        [$neg]
                    );
                });

                continue;
            }

            if ($this->isCaseColorToken($token)) {
                $this->applyCaseColorFilter($builder, $token);
                continue;
            }

            $aliases = $this->expandSearchToken($token);
            $bounded = $this->boundedTokenPatterns($token);
            $builder->where(function ($query) use ($aliases, $bounded, $token, $isNumericSku) {
                if ($bounded !== []) {
                    foreach ($bounded as $pattern) {
                        $query->orWhereRaw('name ~* ?', [$pattern])
                            ->orWhereRaw('part ~* ?', [$pattern]);
                    }
                } else {
                    foreach ($aliases as $alias) {
                        $like = '%'.$this->escapeLike($alias).'%';
                        $query->orWhere('name', 'ilike', $like)
                            ->orWhere('part', 'ilike', $like)
                            ->orWhere('vendor', 'ilike', $like);
                    }
                }
                if ($isNumericSku) {
                    $query->orWhere('sku', (int) $token);
                }
            });
        }

        // Жёсткий фильтр по типу — даже если категории ITP не сматчились
        if (is_array($rule)) {
            $requireNameInclude = $categoryIds === null || $categoryIds === [];
            $this->applyNameTypeFilters($builder, $rule, $requireNameInclude);
        }

        // Вентиляторы: только 120 мм + намёк на CPU/cooler
        if ($type === 'fan') {
            $this->applyFanCpu120Filters($builder);
        }

        // Корпуса: цвет / стекло / форм-фактор через DeepSeek (кэш в БД)
        if ($type === 'case' && $caseFilters !== []) {
            $pre = clone $builder;
            if (! empty($caseFilters['color'])) {
                if ($caseFilters['color'] === 'white') {
                    $pre->where(function ($q) {
                        $q->where('name', 'ilike', '%бел%')
                            ->orWhere('name', 'ilike', '%white%')
                            ->orWhere('case_color', 'white');
                    });
                } else {
                    $pre->where(function ($q) {
                        $q->where('name', 'ilike', '%черн%')
                            ->orWhere('name', 'ilike', '%чёрн%')
                            ->orWhere('name', 'ilike', '%black%')
                            ->orWhere('case_color', 'black');
                    });
                }
            }
            if (! empty($caseFilters['glass'])) {
                $pre->where(function ($q) {
                    $q->where('name', 'ilike', '%стекл%')
                        ->orWhere('name', 'ilike', '%окн%')
                        ->orWhere('name', 'ilike', '%glass%')
                        ->orWhere('name', 'ilike', '%панорам%')
                        ->orWhere('name', 'ilike', '%tempered%')
                        ->orWhere('name', 'ilike', '%перед%')
                        ->orWhereNotNull('case_glass');
                });
            }
            if (! empty($caseFilters['form']) && $caseFilters['form'] === 'atx') {
                $pre->where(function ($q) {
                    $q->where('name', 'ilike', '%atx%')
                        ->orWhere('case_form', 'atx');
                });
            }

            $candidateSkus = $pre->limit(400)->pluck('sku')->all();
            if ($candidateSkus === []) {
                return [];
            }
            app(StoreCaseCatalogEnrichmentService::class)->ensureClassified(
                array_map('intval', $candidateSkus)
            );
            $builder->whereIn('sku', $candidateSkus);
            if (! empty($caseFilters['color'])) {
                $builder->where('case_color', $caseFilters['color']);
            }
            if (! empty($caseFilters['glass'])) {
                $builder->where('case_glass', $caseFilters['glass']);
            }
            if (! empty($caseFilters['form'])) {
                $builder->where('case_form', $caseFilters['form']);
            }
        }

        $rows = $builder
            ->limit(min(400, max($limit * 10, 100)))
            ->get(['sku', 'name', 'part', 'vendor', 'rrp', 'price', 'stock_qty', 'category_external_id', 'has_image', 'image_path']);

        $catNames = $this->categoryNames($rows->pluck('category_external_id')->filter()->unique()->all());

        $scored = $rows->map(function (StoreSupplierCatalogProduct $p) use ($qLower, $catNames, $rule, $tokens, $categoryIds, $type, $caseFilters) {
            $requireNameInclude = $categoryIds === null || $categoryIds === [];
            if (is_array($rule) && ! $this->passesNameTypeRule((string) $p->name, $rule, $requireNameInclude)) {
                return null;
            }
            if ($type === 'fan' && ! $this->passesFanCpu120((string) $p->name)) {
                return null;
            }

            $score = 0;
            if ($tokens === [] && $caseFilters !== []) {
                $score = 20;
            } else {
                foreach ($tokens as $token) {
                    foreach ($this->expandSearchToken($token) as $alias) {
                        $score = max($score, $this->score($alias, $p));
                    }
                    if ($this->isAtxFormFactorToken($token) && $this->isStandaloneAtxName((string) $p->name.' '.(string) $p->part)) {
                        $score = max($score, 40);
                    }
                    if ($this->isCaseColorToken($token)) {
                        $score = max($score, 35);
                    }
                }
            }
            if ($score <= 0) {
                return null;
            }
            if (count($tokens) > 1) {
                $score += min(10, count($tokens) * 2);
            }

            return [
                'sku' => (int) $p->sku,
                'name' => (string) $p->name,
                'part' => $p->part,
                'vendor' => $p->vendor,
                'rrp' => $p->rrp,
                'price' => $p->price !== null ? (float) $p->price : null,
                'stock_qty' => $p->stock_qty,
                'in_stock' => $p->price !== null,
                'category_external_id' => $p->category_external_id ? (int) $p->category_external_id : null,
                'category_name' => $catNames[(int) $p->category_external_id] ?? null,
                'has_image' => (bool) $p->has_image,
                'score' => $score,
            ];
        })->filter()->sort(function (array $a, array $b) {
            // Сначала с ценой (в наличии), затем дешевле → дороже, потом релевантность
            $aStock = $a['in_stock'] ? 1 : 0;
            $bStock = $b['in_stock'] ? 1 : 0;
            if ($aStock !== $bStock) {
                return $bStock <=> $aStock;
            }
            $aPrice = $a['price'] ?? null;
            $bPrice = $b['price'] ?? null;
            if ($aPrice !== null && $bPrice !== null && (float) $aPrice !== (float) $bPrice) {
                return (float) $aPrice <=> (float) $bPrice;
            }
            if ($aPrice !== null && $bPrice === null) {
                return -1;
            }
            if ($aPrice === null && $bPrice !== null) {
                return 1;
            }
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return strcmp($a['name'], $b['name']);
        })->values()->take($limit)->all();

        return array_values($scored);
    }

    private function applyFanCpu120Filters($query): void
    {
        // Диаметр ровно 120 мм (не 1120 / не 1200)
        $query->where(function ($q) {
            foreach ([
                '(^|[^0-9])120\\s*(мм|mm)($|[^0-9a-zа-яё])',
                '(^|[^0-9])120mm($|[^0-9a-zа-яё])',
                'ø\\s*120',
                '⌀\\s*120',
            ] as $pattern) {
                $q->orWhereRaw('name ~* ?', [$pattern]);
            }
        });

        // Отсечь заведомо не-CPU
        foreach (['видеокарт', 'gpu', 'для корпуса', 'case fan', 'корпусной'] as $ex) {
            $query->where('name', 'not ilike', '%'.$this->escapeLike($ex).'%');
        }
    }

    private function passesFanCpu120(string $name): bool
    {
        $n = mb_strtolower($name);
        foreach (['видеокарт', 'gpu', 'для корпуса', 'case fan', 'корпусной'] as $ex) {
            if (str_contains($n, $ex)) {
                return false;
            }
        }

        return (bool) preg_match('/(^|[^0-9])120\s*(мм|mm)|(^|[^0-9])120mm|ø\s*120|⌀\s*120/ui', $n);
    }

    /**
     * @param  array{cat: list<string>, cat_exclude?: list<string>, name_exclude?: list<string>, name_include?: list<string>}  $rule
     */
    private function applyNameTypeFilters($query, array $rule, bool $requireInclude = true): void
    {
        foreach ($rule['name_exclude'] ?? [] as $ex) {
            $query->where('name', 'not ilike', '%'.$this->escapeLike($ex).'%');
        }

        $includes = $rule['name_include'] ?? [];
        if ($requireInclude && $includes !== []) {
            $query->where(function ($q) use ($includes) {
                foreach ($includes as $inc) {
                    $q->orWhere('name', 'ilike', '%'.$this->escapeLike($inc).'%')
                        ->orWhere('part', 'ilike', '%'.$this->escapeLike($inc).'%');
                }
            });
        }
    }

    /**
     * @param  array{name_exclude?: list<string>, name_include?: list<string>}  $rule
     */
    private function passesNameTypeRule(string $name, array $rule, bool $requireInclude = true): bool
    {
        $n = mb_strtolower($name);
        foreach ($rule['name_exclude'] ?? [] as $ex) {
            if (str_contains($n, mb_strtolower($ex))) {
                return false;
            }
        }
        $includes = $rule['name_include'] ?? [];
        if (! $requireInclude || $includes === []) {
            return true;
        }
        foreach ($includes as $inc) {
            if (str_contains($n, mb_strtolower($inc))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>|null null = тип без правил; [] = правила есть, категорий не нашли
     */
    public function categoryIdsForType(string $type): ?array
    {
        $type = strtolower(trim($type));
        $rule = $this->typeRules()[$type] ?? null;
        if ($rule === null) {
            return null;
        }
        $keywords = $rule['cat'] ?? [];
        if ($keywords === []) {
            return null;
        }
        $exclude = $rule['cat_exclude'] ?? [];

        return Cache::remember(self::CACHE_PREFIX.$type, now()->addHours(6), function () use ($keywords, $exclude) {
            $roots = StoreSupplierCatalogCategory::query()
                ->where(function ($q) use ($keywords) {
                    foreach ($keywords as $kw) {
                        $q->orWhere('name', 'ilike', '%'.$this->escapeLike($kw).'%');
                    }
                })
                ->when($exclude !== [], function ($q) use ($exclude) {
                    foreach ($exclude as $ex) {
                        $q->where('name', 'not ilike', '%'.$this->escapeLike($ex).'%');
                    }
                })
                ->pluck('external_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($roots === []) {
                return [];
            }

            return $this->expandWithDescendants($roots);
        });
    }

    private function score(string $qLower, StoreSupplierCatalogProduct $p): int
    {
        $part = mb_strtolower(trim((string) $p->part));
        $name = mb_strtolower(trim((string) $p->name));
        $vendor = mb_strtolower(trim((string) $p->vendor));
        $sku = (string) $p->sku;

        $qCompact = preg_replace('/[\s\-_\/]+/u', '', $qLower) ?? $qLower;
        $partCompact = preg_replace('/[\s\-_\/]+/u', '', $part) ?? $part;
        $nameCompact = preg_replace('/[\s\-_\/]+/u', '', $name) ?? $name;

        if ($part !== '' && $part === $qLower) {
            return 100;
        }
        if ($partCompact !== '' && $partCompact === $qCompact) {
            return 98;
        }
        if ($sku === $qLower || $sku === $qCompact) {
            return 95;
        }
        if ($part !== '' && str_starts_with($part, $qLower)) {
            return 90;
        }
        if ($partCompact !== '' && str_contains($partCompact, $qCompact)) {
            return 85;
        }
        if ($part !== '' && str_contains($part, $qLower)) {
            return 80;
        }
        if ($nameCompact !== '' && str_contains($nameCompact, $qCompact)) {
            return 70;
        }
        if ($name !== '' && str_contains($name, $qLower)) {
            return 60;
        }
        if ($vendor !== '' && str_contains($vendor, $qLower)) {
            return 30;
        }

        return 0;
    }

    /**
     * @param  list<int>  $externalIds
     * @return array<int, string>
     */
    private function categoryNames(array $externalIds): array
    {
        if ($externalIds === []) {
            return [];
        }

        return StoreSupplierCatalogCategory::query()
            ->whereIn('external_id', $externalIds)
            ->pluck('name', 'external_id')
            ->mapWithKeys(fn ($name, $id) => [(int) $id => (string) $name])
            ->all();
    }

    /**
     * @return list<int>
     */
    private function categorySubtreeIds(int $rootExternalId): array
    {
        return $this->expandWithDescendants([$rootExternalId]);
    }

    /**
     * @param  list<int>  $roots
     * @return list<int>
     */
    private function expandWithDescendants(array $roots): array
    {
        $all = StoreSupplierCatalogCategory::query()->get(['external_id', 'parent_external_id']);
        $byParent = [];
        foreach ($all as $cat) {
            $pid = $cat->parent_external_id ? (int) $cat->parent_external_id : 0;
            $byParent[$pid][] = (int) $cat->external_id;
        }

        $include = [];
        $stack = array_map('intval', $roots);
        while ($stack !== []) {
            $id = (int) array_pop($stack);
            if (isset($include[$id])) {
                continue;
            }
            $include[$id] = true;
            foreach ($byParent[$id] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return array_map('intval', array_keys($include));
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Синонимы объёма: 1000GB ↔ 1TB, 2000GB ↔ 2TB, …
     *
     * @return list<string>
     */
    private function expandSearchToken(string $token): array
    {
        $t = mb_strtolower(trim($token));
        $t = preg_replace('/\s+/u', '', $t) ?? $t;

        $groups = [
            '256gb' => ['256gb', '256 gb', '256гб', '256 гб'],
            '500gb' => ['500gb', '500 gb', '500гб', '500 гб', '512gb', '512 gb', '512гб', '512 гб'],
            '1000gb' => ['1000gb', '1000 gb', '1000гб', '1000 гб', '1tb', '1 tb', '1тб', '1 тб', '1.0tb'],
            '2000gb' => ['2000gb', '2000 gb', '2000гб', '2000 гб', '2tb', '2 tb', '2тб', '2 тб', '2.0tb'],
            '4000gb' => ['4000gb', '4000 gb', '4000гб', '4000 гб', '4tb', '4 tb', '4тб', '4 тб', '4.0tb'],
            // HDD
            '2tb' => ['2tb', '2 tb', '2тб', '2 тб', '2.0tb', '2000gb', '2000 gb', '2000гб'],
            '4tb' => ['4tb', '4 tb', '4тб', '4 тб', '4.0tb', '4000gb', '4000 gb', '4000гб'],
            '6tb' => ['6tb', '6 tb', '6тб', '6 тб', '6.0tb', '6000gb', '6000 gb', '6000гб'],
            '8tb' => ['8tb', '8 tb', '8тб', '8 тб', '8.0tb', '8000gb', '8000 gb', '8000гб'],
            // Вентиляторы
            '120mm' => ['120mm', '120 mm', '120мм', '120 мм'],
            // БП
            '500w' => ['500w', '500 w', '500вт', '500 вт', '500watt'],
            '600w' => ['600w', '600 w', '600вт', '600 вт', '600watt'],
            '700w' => ['700w', '700 w', '700вт', '700 вт', '700watt'],
            '800w' => ['800w', '800 w', '800вт', '800 вт', '800watt'],
            '1000w' => ['1000w', '1000 w', '1000вт', '1000 вт', '1000watt', '1kw'],
            // Корпуса (цвета оставлены для не-case поиска; для case — splitCaseFilterTokens)
            'белый' => ['белый', 'белая', 'белое', 'white'],
            'черный' => ['черный', 'чёрный', 'черная', 'чёрная', 'черное', 'чёрное', 'black'],
        ];

        // Нормализация входа к ключу группы
        $map = [
            '256gb' => '256gb', '256гб' => '256gb',
            '500gb' => '500gb', '500гб' => '500gb', '512gb' => '500gb', '512гб' => '500gb',
            '1000gb' => '1000gb', '1000гб' => '1000gb', '1tb' => '1000gb', '1тб' => '1000gb', '1.0tb' => '1000gb',
            '2000gb' => '2000gb', '2000гб' => '2000gb',
            '4000gb' => '4000gb', '4000гб' => '4000gb',
            // HDD / TB — 2tb не смешиваем с SSD-ключом 2000gb как входом чипа
            '2tb' => '2tb', '2тб' => '2tb', '2.0tb' => '2tb',
            '4tb' => '4tb', '4тб' => '4tb', '4.0tb' => '4tb',
            '6tb' => '6tb', '6тб' => '6tb', '6.0tb' => '6tb', '6000gb' => '6tb', '6000гб' => '6tb',
            '8tb' => '8tb', '8тб' => '8tb', '8.0tb' => '8tb', '8000gb' => '8tb', '8000гб' => '8tb',
            // БП
            '500w' => '500w', '500вт' => '500w', '500watt' => '500w',
            '600w' => '600w', '600вт' => '600w', '600watt' => '600w',
            '700w' => '700w', '700вт' => '700w', '700watt' => '700w',
            '800w' => '800w', '800вт' => '800w', '800watt' => '800w',
            '1000w' => '1000w', '1000вт' => '1000w', '1000watt' => '1000w', '1kw' => '1000w',
            // Корпуса (цвета)
            'белый' => 'белый', 'белая' => 'белый', 'белое' => 'белый', 'white' => 'белый',
            'черный' => 'черный', 'чёрный' => 'черный', 'черная' => 'черный', 'чёрная' => 'черный',
            'черное' => 'черный', 'чёрное' => 'черный', 'black' => 'черный',
        ];

        $key = $map[$t] ?? null;
        if ($key === null) {
            return [$token];
        }

        return $groups[$key];
    }

    /**
     * Regex-паттерны с границей числа: 700Вт не матчит 1700Вт.
     *
     * @return list<string>
     */
    private function boundedTokenPatterns(string $token): array
    {
        $t = mb_strtolower(trim($token));
        $t = preg_replace('/\s+/u', '', $t) ?? $t;

        // Вт / W
        if (preg_match('/^(\d{3,4})(вт|w|watt)$/ui', $t, $m)) {
            $n = $m[1];

            return [
                '(^|[^0-9])'.$n.'\\s*(вт|w|watt)($|[^0-9a-zа-яё])',
            ];
        }

        // TB / ТБ / GB / ГБ / мм
        if (preg_match('/^(\d{1,4})(tb|тб|gb|гб|мм|mm)$/ui', $t, $m)) {
            $n = $m[1];
            $unit = mb_strtolower($m[2]);
            $units = match (true) {
                in_array($unit, ['tb', 'тб'], true) => 'tb|тб',
                in_array($unit, ['мм', 'mm'], true) => 'мм|mm',
                default => 'gb|гб',
            };

            return [
                '(^|[^0-9])'.$n.'\\s*('.$units.')($|[^0-9a-zа-яё])',
            ];
        }

        // Ключи синонимов 500w / 2tb / 1000gb — тоже с границами
        $compact = preg_replace('/\s+/u', '', mb_strtolower($token)) ?? mb_strtolower($token);
        if ($this->expandSearchTokenKey($compact) !== null) {
            $key = $this->expandSearchTokenKey($compact);
            if (str_ends_with($key, 'w')) {
                $n = substr($key, 0, -1);

                return ['(^|[^0-9])'.$n.'\\s*(вт|w|watt)($|[^0-9a-zа-яё])'];
            }
            if (str_ends_with($key, 'tb')) {
                $n = substr($key, 0, -2);

                return ['(^|[^0-9])'.$n.'\\s*(tb|тб)($|[^0-9a-zа-яё])'];
            }
            if (str_ends_with($key, 'mm')) {
                $n = substr($key, 0, -2);

                return [
                    '(^|[^0-9])'.$n.'\\s*(мм|mm)($|[^0-9a-zа-яё])',
                    '(^|[^0-9])'.$n.'mm($|[^0-9a-zа-яё])',
                ];
            }
            if (str_ends_with($key, 'gb')) {
                $n = substr($key, 0, -2);
                $patterns = ['(^|[^0-9])'.$n.'\\s*(gb|гб)($|[^0-9a-zа-яё])'];
                // 1000gb ≈ 1tb
                if ($n === '1000') {
                    $patterns[] = '(^|[^0-9])1\\s*(tb|тб)($|[^0-9a-zа-яё])';
                }
                if ($n === '2000') {
                    $patterns[] = '(^|[^0-9])2\\s*(tb|тб)($|[^0-9a-zа-яё])';
                }
                if ($n === '4000') {
                    $patterns[] = '(^|[^0-9])4\\s*(tb|тб)($|[^0-9a-zа-яё])';
                }
                if ($n === '500') {
                    $patterns[] = '(^|[^0-9])512\\s*(gb|гб)($|[^0-9a-zа-яё])';
                }

                return $patterns;
            }
        }

        return [];
    }

    /**
     * Чипы корпусов → фильтры по кэшу DeepSeek / эвристике.
     *
     * @param  list<string>  $tokens
     * @return array{0: array{color?:string,glass?:string,form?:string}, 1: list<string>}
     */
    private function splitCaseFilterTokens(array $tokens): array
    {
        $filters = [];
        $rest = [];
        foreach ($tokens as $token) {
            $t = mb_strtolower(trim($token));
            if (in_array($t, ['белый', 'белая', 'белое', 'white'], true)) {
                $filters['color'] = 'white';
                continue;
            }
            if (in_array($t, ['черный', 'чёрный', 'черная', 'чёрная', 'черное', 'чёрное', 'black'], true)) {
                $filters['color'] = 'black';
                continue;
            }
            if ($this->isSideGlassToken($token)) {
                $filters['glass'] = 'side';
                continue;
            }
            if ($this->isFrontSideGlassToken($token)) {
                $filters['glass'] = 'front_side';
                continue;
            }
            if ($this->isAtxFormFactorToken($token)) {
                $filters['form'] = 'atx';
                continue;
            }
            $rest[] = $token;
        }

        return [$filters, $rest];
    }

    private function isCaseColorToken(string $token): bool
    {
        $t = mb_strtolower(trim($token));

        return in_array($t, [
            'белый', 'белая', 'белое', 'white',
            'черный', 'чёрный', 'черная', 'чёрная', 'черное', 'чёрное', 'black',
        ], true);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\StoreSupplierCatalogProduct>  $builder
     */
    private function applyCaseColorFilter($builder, string $token): void
    {
        $t = mb_strtolower(trim($token));
        $patterns = in_array($t, ['белый', 'белая', 'белое', 'white'], true)
            ? [
                '(^|[^a-zа-яё0-9])(белый|белая|белое|white)([^a-zа-яё0-9]|$)',
            ]
            : [
                '(^|[^a-zа-яё0-9])(черный|чёрный|черная|чёрная|черное|чёрное|black)([^a-zа-яё0-9]|$)',
            ];

        $builder->where(function ($query) use ($patterns) {
            foreach ($patterns as $pattern) {
                $query->orWhereRaw('name ~* ?', [$pattern])
                    ->orWhereRaw('part ~* ?', [$pattern]);
            }
        });
    }

    private function isAtxFormFactorToken(string $token): bool
    {
        return mb_strtolower(trim($token)) === 'atx';
    }

    private function isSideGlassToken(string $token): bool
    {
        return in_array(mb_strtolower(trim($token)), ['боковое', 'сбоку'], true);
    }

    private function isFrontSideGlassToken(string $token): bool
    {
        $t = mb_strtolower(trim($token));

        return in_array($t, ['frontglass', 'панорам', 'спереди'], true);
    }

    /**
     * @param  list<string>  $tokens
     */
    private function queryHasAtxToken(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($this->isAtxFormFactorToken($token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function queryHasSideGlassToken(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($this->isSideGlassToken($token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function queryHasFrontSideGlassToken(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($this->isFrontSideGlassToken($token)) {
                return true;
            }
        }

        return false;
    }

    /** Полноценный ATX, без mATX / MicroATX / Mini-ATX. */
    private function isStandaloneAtxName(string $name): bool
    {
        $n = mb_strtolower($name);
        if (preg_match('/(^|[^a-zа-яё0-9])(m|micro|mini)\s*-?\s*atx([^a-zа-яё0-9]|$)/u', $n)) {
            return false;
        }

        return (bool) preg_match('/(^|[^a-zа-яё0-9])atx([^a-zа-яё0-9]|$)/u', $n);
    }

    /**
     * Боковое стекло / окно (в т.ч. «боковое окно (панорама)»).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\StoreSupplierCatalogProduct>  $builder
     */
    private function applySideGlassFilter($builder): void
    {
        $patterns = $this->sideGlassPatterns();
        $builder->where(function ($query) use ($patterns) {
            foreach ($patterns as $pattern) {
                $query->orWhereRaw('name ~* ?', [$pattern])
                    ->orWhereRaw('part ~* ?', [$pattern]);
            }
        });
    }

    /**
     * Стекло и спереди, и сбоку — не путать с одним боковым «панорамным» окном.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\StoreSupplierCatalogProduct>  $builder
     */
    private function applyFrontSideGlassFilter($builder): void
    {
        $patterns = $this->frontSideGlassPatterns();
        $builder->where(function ($query) use ($patterns) {
            foreach ($patterns as $pattern) {
                $query->orWhereRaw('name ~* ?', [$pattern])
                    ->orWhereRaw('part ~* ?', [$pattern]);
            }
        });
    }

    /**
     * @return list<string>
     */
    private function sideGlassPatterns(): array
    {
        return [
            'боков\\w{0,8}\\s*(окн|стекл)',
            '(окн|стекл)\\w{0,8}\\s*боков',
            'с\\s+окном',
            'боковое\\s+окно',
            'tempered\\s*glass',
            'side\\s*(window|glass|panel)',
        ];
    }

    /**
     * @return list<string>
     */
    private function frontSideGlassPatterns(): array
    {
        // Переднее+боковое / панорама / dual — не путать с одним боковым окном
        return [
            'передн\\w{0,12}.{0,50}(стекл|окн|glass|tg)',
            '(стекл|окн|glass).{0,50}передн\\w{0,12}',
            'спереди.{0,40}(стекл|окн|glass|боков|сбоку)',
            '(стекл|окн|glass).{0,40}спереди',
            'front.{0,40}(glass|tg|window|panel).{0,40}(side|lateral)',
            '(side|lateral).{0,40}(glass|tg|window).{0,40}front',
            'dual[\\s-]*glass',
            'full[\\s-]*glass',
            'double[\\s-]*glass',
            'передн\\w{0,12}.{0,40}боков',
            'боков\\w{0,8}.{0,40}передн',
            'спереди.{0,25}(и\\s+)?сбоку',
            'сбоку.{0,25}(и\\s+)?спереди',
            'передн\\w{0,12}\\s+и\\s+боков',
            'стеклянн\\w{0,8}\\s+передн',
            'панорам',
            'panoram',
            'fishbowl',
            'seamless',
            'без\\s+стойк',
        ];
    }

    private function isSideGlassName(string $hay): bool
    {
        foreach ($this->sideGlassPatterns() as $pattern) {
            if (preg_match('/'.$pattern.'/ui', $hay)) {
                return true;
            }
        }

        return false;
    }

    private function isFrontSideGlassName(string $hay): bool
    {
        foreach ($this->frontSideGlassPatterns() as $pattern) {
            if (preg_match('/'.$pattern.'/ui', $hay)) {
                return true;
            }
        }

        return false;
    }

    private function expandSearchTokenKey(string $compact): ?string
    {
        $map = [
            '256gb' => '256gb', '256гб' => '256gb',
            '500gb' => '500gb', '500гб' => '500gb', '512gb' => '500gb', '512гб' => '500gb',
            '1000gb' => '1000gb', '1000гб' => '1000gb', '1tb' => '1000gb', '1тб' => '1000gb',
            '2000gb' => '2000gb', '2000гб' => '2000gb',
            '4000gb' => '4000gb', '4000гб' => '4000gb',
            '2tb' => '2tb', '2тб' => '2tb',
            '4tb' => '4tb', '4тб' => '4tb',
            '6tb' => '6tb', '6тб' => '6tb',
            '8tb' => '8tb', '8тб' => '8tb',
            '500w' => '500w', '500вт' => '500w',
            '600w' => '600w', '600вт' => '600w',
            '700w' => '700w', '700вт' => '700w',
            '800w' => '800w', '800вт' => '800w',
            '1000w' => '1000w', '1000вт' => '1000w',
            '120мм' => '120mm', '120mm' => '120mm',
        ];

        return $map[$compact] ?? null;
    }
}
