<?php

namespace App\Services;

use App\Models\StoreSupplierCatalogCategory;
use App\Models\StoreSupplierCatalogProduct;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StoreSupplierCatalogSyncService
{
    public function __construct(private QuickFoxApiClient $api) {}

    /**
     * @return array{categories: int, products: int, priced: int}
     */
    public function sync(bool $withPrices = true): array
    {
        if (! $this->api->isConfigured()) {
            throw new \RuntimeException('QuickFox не настроен (STORE_QUICKFOX_*).');
        }

        $tree = $this->api->downloadCatalogTree();
        $allowedCategoryIds = $this->configuredCategoryIds();
        $flatCategories = [];
        $this->flattenTree($tree, null, $flatCategories);

        $now = now();
        $nowStr = $now->format('Y-m-d H:i:s');
        $categoryRows = [];
        $categoryIds = [];
        foreach ($flatCategories as $cat) {
            $categoryIds[] = $cat['external_id'];
            $categoryRows[] = [
                'external_id' => $cat['external_id'],
                'parent_external_id' => $cat['parent_external_id'],
                'name' => $cat['name'],
                'leaf' => $cat['leaf'] ? 1 : 0,
                'synced_at' => $nowStr,
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ];
        }

        DB::transaction(function () use ($categoryRows, $categoryIds, $nowStr) {
            foreach (array_chunk($categoryRows, 500) as $chunk) {
                StoreSupplierCatalogCategory::query()->upsert(
                    $chunk,
                    ['external_id'],
                    ['parent_external_id', 'name', 'leaf', 'synced_at', 'updated_at']
                );
            }
            if ($categoryIds !== []) {
                StoreSupplierCatalogCategory::query()
                    ->whereNotIn('external_id', $categoryIds)
                    ->delete();
            } else {
                StoreSupplierCatalogCategory::query()->delete();
            }
        });

        $includeIds = null;
        if ($allowedCategoryIds !== []) {
            $includeIds = $this->expandWithDescendants($allowedCategoryIds);
        }

        $products = $this->api->downloadProducts();
        $productRows = [];
        $seenSkus = [];
        foreach ($products as $p) {
            if (! is_array($p) || empty($p['sku'])) {
                continue;
            }
            $sku = (int) $p['sku'];
            if ($sku <= 0 || isset($seenSkus[$sku])) {
                continue;
            }
            $categoryId = isset($p['category']) ? (int) $p['category'] : null;
            if ($includeIds !== null && ($categoryId === null || ! isset($includeIds[$categoryId]))) {
                continue;
            }

            $seenSkus[$sku] = true;
            $productRows[] = [
                'sku' => $sku,
                'category_external_id' => $categoryId,
                'name' => mb_substr(trim((string) ($p['name'] ?? '')), 0, 2000),
                'part' => $this->nullableString($p['part'] ?? null, 2000),
                'vendor' => $this->nullableString($p['vendor'] ?? null, 512),
                'rrp' => $this->nullableFloat($p['rrp'] ?? null),
                'warranty' => $this->nullableString($p['warranty'] ?? null, 64),
                'multiplicity' => max(0, (int) ($p['multiplicity'] ?? 1)),
                'barcodes' => $this->nullableString($p['barcodes'] ?? null, 4000),
                'has_image' => ! empty($p['has_image']) ? 1 : 0,
                // только для INSERT; при UPDATE не трогаем (см. upsert update columns)
                'image_path' => null,
                'image_paths' => null,
                'image_synced_at' => null,
                'case_color' => null,
                'case_glass' => null,
                'case_form' => null,
                'case_attrs_at' => null,
                'price' => null,
                'stock_qty' => null,
                'price_synced_at' => null,
                'synced_at' => $nowStr,
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ];
        }

        // Если name/part сменились — сбросить DeepSeek-разметку (иначе устареет)
        $resetCaseSkus = [];
        $rowsBySku = [];
        foreach ($productRows as $row) {
            $rowsBySku[$row['sku']] = $row;
        }
        foreach (array_chunk(array_keys($rowsBySku), 2000) as $skuChunk) {
            $existing = StoreSupplierCatalogProduct::query()
                ->whereIn('sku', $skuChunk)
                ->get(['sku', 'name', 'part']);
            foreach ($existing as $old) {
                $row = $rowsBySku[(int) $old->sku] ?? null;
                if (! $row) {
                    continue;
                }
                if ($this->norm($old->name) !== $this->norm($row['name'])
                    || $this->norm($old->part) !== $this->norm($row['part'])) {
                    $resetCaseSkus[] = (int) $old->sku;
                }
            }
        }
        unset($rowsBySku);

        DB::transaction(function () use ($productRows, $resetCaseSkus, $nowStr) {
            foreach (array_chunk($productRows, 400) as $chunk) {
                // Не затираем: case_*, image_*
                // Цены обнуляем: «в наличии» только после syncPrices по get_active_products
                StoreSupplierCatalogProduct::query()->upsert(
                    $chunk,
                    ['sku'],
                    [
                        'category_external_id',
                        'name',
                        'part',
                        'vendor',
                        'rrp',
                        'warranty',
                        'multiplicity',
                        'barcodes',
                        'has_image',
                        'price',
                        'stock_qty',
                        'price_synced_at',
                        'synced_at',
                        'updated_at',
                    ]
                );
            }

            // Нет в текущем файле прайса ITP — убираем из локального каталога
            StoreSupplierCatalogProduct::query()
                ->where(function ($q) use ($nowStr) {
                    $q->whereNull('synced_at')
                        ->orWhere('synced_at', '<', $nowStr);
                })
                ->delete();

            if ($resetCaseSkus !== []) {
                foreach (array_chunk($resetCaseSkus, 500) as $chunk) {
                    StoreSupplierCatalogProduct::query()
                        ->whereIn('sku', $chunk)
                        ->update([
                            'case_color' => null,
                            'case_glass' => null,
                            'case_form' => null,
                            'case_attrs_at' => null,
                            'updated_at' => $nowStr,
                        ]);
                }
            }

            // Нет картинки у поставщика — чистим кэш путей
            StoreSupplierCatalogProduct::query()
                ->where('has_image', false)
                ->where(function ($q) {
                    $q->whereNotNull('image_path')
                        ->orWhereNotNull('image_paths')
                        ->orWhereNotNull('image_synced_at');
                })
                ->update([
                    'image_path' => null,
                    'image_paths' => null,
                    'image_synced_at' => null,
                    'updated_at' => $nowStr,
                ]);
        });

        $this->clearSearchCache();

        $priced = 0;
        if ($withPrices) {
            $priced = $this->syncPrices();
        }

        return [
            'categories' => count($categoryRows),
            'products' => count($productRows),
            'priced' => $priced,
        ];
    }

    /**
     * Один запрос get_active_products → price/qty в локальный каталог.
     * Лимит API: 10/час. Цена только у товаров в наличии.
     */
    public function syncPrices(): int
    {
        if (! $this->api->isConfigured()) {
            throw new \RuntimeException('QuickFox не настроен (STORE_QUICKFOX_*).');
        }

        $active = $this->api->getAllActiveProducts();
        $now = now();

        StoreSupplierCatalogProduct::query()->update([
            'price' => null,
            'stock_qty' => null,
            'price_synced_at' => null,
        ]);

        $rows = [];
        foreach ($active as $p) {
            if (! is_array($p) || empty($p['sku'])) {
                continue;
            }
            if (! isset($p['price']) || ! is_numeric($p['price'])) {
                continue;
            }
            $qty = null;
            if (isset($p['real_qty']) && is_numeric($p['real_qty'])) {
                $qty = (int) $p['real_qty'];
            } elseif (isset($p['qty']) && is_numeric($p['qty'])) {
                $qty = (int) $p['qty'];
            }
            $rows[] = [
                'sku' => (int) $p['sku'],
                'price' => (float) $p['price'],
                'stock_qty' => $qty,
            ];
        }

        $updated = 0;
        foreach (array_chunk($rows, 400) as $chunk) {
            $updated += $this->bulkUpdatePrices($chunk, $now);
        }

        return $updated;
    }

    /**
     * @param  list<array{sku:int,price:float,stock_qty:?int}>  $chunk
     */
    private function bulkUpdatePrices(array $chunk, \DateTimeInterface $now): int
    {
        if ($chunk === []) {
            return 0;
        }

        $driver = Schema::getConnection()->getDriverName();
        $nowStr = $now->format('Y-m-d H:i:s');

        if ($driver === 'pgsql') {
            $values = [];
            $bindings = [];
            foreach ($chunk as $row) {
                $values[] = '(?::bigint, ?::numeric, ?::integer)';
                $bindings[] = $row['sku'];
                $bindings[] = $row['price'];
                $bindings[] = $row['stock_qty'];
            }
            $sql = '
                UPDATE store_supplier_catalog_products AS p
                SET price = v.price,
                    stock_qty = v.stock_qty,
                    price_synced_at = ?,
                    updated_at = ?
                FROM (VALUES '.implode(',', $values).') AS v(sku, price, stock_qty)
                WHERE p.sku = v.sku
            ';

            return DB::update($sql, array_merge([$nowStr, $nowStr], $bindings));
        }

        $count = 0;
        DB::transaction(function () use ($chunk, $nowStr, &$count) {
            foreach ($chunk as $row) {
                $count += DB::table('store_supplier_catalog_products')
                    ->where('sku', $row['sku'])
                    ->update([
                        'price' => $row['price'],
                        'stock_qty' => $row['stock_qty'],
                        'price_synced_at' => $nowStr,
                        'updated_at' => $nowStr,
                    ]);
            }
        });

        return $count;
    }

    public function clearSearchCache(): void
    {
        foreach (array_keys((new StoreSupplierCatalogSearchService)->typeRules()) as $type) {
            Cache::forget('store.quickfox.cat_ids.v3.'.$type);
            Cache::forget('store.quickfox.cat_ids.v4.'.$type);
            Cache::forget('store.quickfox.cat_ids.'.$type);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array{external_id: int, parent_external_id: int|null, name: string, leaf: bool}>  $out
     */
    private function flattenTree(array $nodes, ?int $parentId, array &$out): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node) || ! isset($node['id'])) {
                continue;
            }
            $id = (int) $node['id'];
            $children = $node['childrens'] ?? $node['children'] ?? [];
            $children = is_array($children) ? $children : [];
            $out[] = [
                'external_id' => $id,
                'parent_external_id' => $parentId,
                'name' => (string) ($node['name'] ?? ''),
                'leaf' => (bool) ($node['leaf'] ?? ($children === [])),
            ];
            if ($children !== []) {
                $this->flattenTree($children, $id, $out);
            }
        }
    }

    /**
     * @return list<int>
     */
    private function configuredCategoryIds(): array
    {
        $raw = config('store.quickfox.category_ids', []);
        if (is_string($raw)) {
            $raw = array_filter(array_map('trim', explode(',', $raw)));
        }
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $raw)));
    }

    /**
     * @param  list<int>  $roots
     * @return array<int, true>
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
        $stack = $roots;
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

        return $include;
    }

    private function norm(mixed $value): string
    {
        return mb_strtolower(trim((string) ($value ?? '')));
    }

    private function nullableString(mixed $value, int $maxLen): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);
        if ($s === '' || $s === '?' || strcasecmp($s, 'null') === 0) {
            return null;
        }

        return mb_substr($s, 0, $maxLen);
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === '?') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
