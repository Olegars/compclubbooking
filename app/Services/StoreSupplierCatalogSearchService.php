<?php

namespace App\Services;

use App\Models\StoreSupplierCatalogCategory;
use App\Models\StoreSupplierCatalogProduct;
use Illuminate\Support\Facades\Cache;

class StoreSupplierCatalogSearchService
{
    private const CACHE_PREFIX = 'store.quickfox.cat_ids.v3.';

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
                'cat' => ['блок питания', 'power supply', 'psu'],
                'name_exclude' => ['материнск', 'видеокарт', 'процессор'],
                'name_include' => ['блок питания', 'psu', 'watt', 'вт'],
            ],
            'case' => [
                'cat' => ['корпус'],
                'cat_exclude' => ['блок питания'],
                'name_include' => ['корпус'],
            ],
            'cooler' => [
                'cat' => ['кулер', 'охлажден', 'cooler', 'термопаст'],
                'name_exclude' => ['корпус', 'блок питания', 'материнск'],
                'name_include' => ['кулер', 'охлажд', 'cooler', 'термопаст', 'водян'],
            ],
            'fan' => [
                'cat' => ['вентилятор'],
                'name_include' => ['вентилятор', 'fan'],
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
        $like = '%'.$this->escapeLike($q).'%';
        $isNumericSku = (bool) preg_match('/^\d{4,}$/', $q);

        $builder = StoreSupplierCatalogProduct::query()
            ->when($inStockOnly, fn ($query) => $query->whereNotNull('price'))
            ->when($categoryIds !== null && $categoryIds !== [], function ($query) use ($categoryIds) {
                $query->whereIn('category_external_id', $categoryIds);
            })
            ->where(function ($query) use ($like, $q, $isNumericSku) {
                $query->where('name', 'ilike', $like)
                    ->orWhere('part', 'ilike', $like)
                    ->orWhere('vendor', 'ilike', $like);
                if ($isNumericSku) {
                    $query->orWhere('sku', (int) $q);
                }
            });

        // Жёсткий фильтр по типу — даже если категории ITP не сматчились
        if (is_array($rule)) {
            $this->applyNameTypeFilters($builder, $rule);
        }

        $rows = $builder
            ->limit(min(400, max($limit * 10, 100)))
            ->get(['sku', 'name', 'part', 'vendor', 'rrp', 'price', 'stock_qty', 'category_external_id']);

        $catNames = $this->categoryNames($rows->pluck('category_external_id')->filter()->unique()->all());

        $scored = $rows->map(function (StoreSupplierCatalogProduct $p) use ($qLower, $catNames, $rule) {
            if (is_array($rule) && ! $this->passesNameTypeRule((string) $p->name, $rule)) {
                return null;
            }
            $score = $this->score($qLower, $p);
            if ($score <= 0) {
                return null;
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
                'score' => $score,
            ];
        })->filter()->sort(function (array $a, array $b) {
            $aStock = $a['in_stock'] ? 1 : 0;
            $bStock = $b['in_stock'] ? 1 : 0;
            if ($aStock !== $bStock) {
                return $bStock <=> $aStock;
            }
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return strcmp($a['name'], $b['name']);
        })->values()->take($limit)->all();

        return array_values($scored);
    }

    /**
     * @param  array{cat: list<string>, cat_exclude?: list<string>, name_exclude?: list<string>, name_include?: list<string>}  $rule
     */
    private function applyNameTypeFilters($query, array $rule): void
    {
        foreach ($rule['name_exclude'] ?? [] as $ex) {
            $query->where('name', 'not ilike', '%'.$this->escapeLike($ex).'%');
        }

        $includes = $rule['name_include'] ?? [];
        if ($includes !== []) {
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
    private function passesNameTypeRule(string $name, array $rule): bool
    {
        $n = mb_strtolower($name);
        foreach ($rule['name_exclude'] ?? [] as $ex) {
            if (str_contains($n, mb_strtolower($ex))) {
                return false;
            }
        }
        $includes = $rule['name_include'] ?? [];
        if ($includes === []) {
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
}
