<?php

namespace App\Services;

use App\Models\StoreSupplierCatalogCategory;
use App\Models\StoreSupplierCatalogProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class StoreSupplierCatalogSearchService
{
    /**
     * Ключевые слова названий категорий ITP → наш тип комплектующего.
     *
     * @return array<string, list<string>>
     */
    public function typeKeywords(): array
    {
        $custom = config('store.quickfox.type_category_keywords');
        if (is_array($custom) && $custom !== []) {
            return $custom;
        }

        return [
            'cpu' => ['процессор', 'cpu', 'processor'],
            'motherboard' => ['материнск', 'motherboard', 'системная плата'],
            'ram' => ['оперативн', 'память ddr', 'модуль памяти', 'dimm', 'so-dimm', 'озу'],
            'gpu' => ['видеокарт', 'видеоадаптер', 'graphics', 'gpu'],
            'storage_ssd' => ['ssd', 'nvme', 'твердотельн', 'накопител'],
            'storage_hdd' => ['hdd', 'жестк', 'жёстк', 'винчестер'],
            'psu' => ['блок питания', 'power supply', 'psu'],
            'case' => ['корпус', 'case chassis'],
            'cooler' => ['кулер', 'охлажден', 'cooler', 'термопаст'],
            'fan' => ['вентилятор', 'fan'],
            'network' => ['сетев', 'wifi', 'wi-fi', 'адаптер беспровод'],
            'os' => ['операционн', 'windows', 'лицензи'],
        ];
    }

    /**
     * @return list<array{sku:int,name:string,part:?string,vendor:?string,rrp:mixed,category_external_id:?int,score:int,category_name:?string}>
     */
    public function search(string $q, ?string $type = null, ?int $categoryId = null, int $limit = 40): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }

        $categoryIds = null;
        if ($categoryId) {
            $categoryIds = $this->categorySubtreeIds($categoryId);
        } elseif ($type) {
            $categoryIds = $this->categoryIdsForType($type);
            // Ключевые слова не совпали с деревом ITP — ищем по всему каталогу, но без sku-подстроки
            if ($categoryIds === []) {
                $categoryIds = null;
            }
        }

        $qLower = mb_strtolower($q);
        $like = '%'.$this->escapeLike($q).'%';
        $prefix = $this->escapeLike($q).'%';
        $isNumericSku = (bool) preg_match('/^\d{4,}$/', $q);

        $builder = StoreSupplierCatalogProduct::query()
            ->when($categoryIds !== null && $categoryIds !== [], function ($query) use ($categoryIds) {
                $query->whereIn('category_external_id', $categoryIds);
            })
            ->where(function ($query) use ($like, $q, $isNumericSku) {
                $query->where('name', 'ilike', $like)
                    ->orWhere('part', 'ilike', $like)
                    ->orWhere('vendor', 'ilike', $like);

                // SKU — только точное совпадение (иначе 13400 → 11340006 клещи)
                if ($isNumericSku) {
                    $query->orWhere('sku', (int) $q);
                }
            });

        // Берём с запасом, ранжируем в PHP
        $rows = $builder
            ->limit(min(300, max($limit * 8, 80)))
            ->get(['sku', 'name', 'part', 'vendor', 'rrp', 'category_external_id']);

        $catNames = $this->categoryNames($rows->pluck('category_external_id')->filter()->unique()->all());

        $scored = $rows->map(function (StoreSupplierCatalogProduct $p) use ($qLower, $catNames) {
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
                'category_external_id' => $p->category_external_id ? (int) $p->category_external_id : null,
                'category_name' => $catNames[(int) $p->category_external_id] ?? null,
                'score' => $score,
            ];
        })->filter()->sort(function (array $a, array $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return strcmp($a['name'], $b['name']);
        })->values()->take($limit)->all();

        return array_values($scored);
    }

    /**
     * @return list<int>|null null = без фильтра; [] = тип задан, категорий не нашли
     */
    public function categoryIdsForType(string $type): ?array
    {
        $type = strtolower(trim($type));
        $keywords = $this->typeKeywords()[$type] ?? null;
        if ($keywords === null || $keywords === []) {
            return null;
        }

        return Cache::remember('store.quickfox.cat_ids.'.$type, now()->addHours(6), function () use ($keywords) {
            $roots = StoreSupplierCatalogCategory::query()
                ->where(function ($q) use ($keywords) {
                    foreach ($keywords as $kw) {
                        $q->orWhere('name', 'ilike', '%'.$this->escapeLike($kw).'%');
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

        // Нормализация: убрать пробелы/дефисы для моделей вроде 13400F
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
        /** @var Collection<int, object{external_id:int,parent_external_id:?int}> $all */
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
