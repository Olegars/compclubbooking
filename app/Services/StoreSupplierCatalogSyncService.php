<?php

namespace App\Services;

use App\Models\StoreSupplierCatalogCategory;
use App\Models\StoreSupplierCatalogProduct;
use Illuminate\Support\Facades\DB;

class StoreSupplierCatalogSyncService
{
    public function __construct(private QuickFoxApiClient $api) {}

    /**
     * @return array{categories: int, products: int}
     */
    public function sync(): array
    {
        if (! $this->api->isConfigured()) {
            throw new \RuntimeException('QuickFox не настроен (STORE_QUICKFOX_*).');
        }

        $tree = $this->api->downloadCatalogTree();
        $allowedCategoryIds = $this->configuredCategoryIds();
        $flatCategories = [];
        $this->flattenTree($tree, null, $flatCategories);

        $now = now();
        $categoryRows = [];
        foreach ($flatCategories as $cat) {
            $categoryRows[] = [
                'external_id' => $cat['external_id'],
                'parent_external_id' => $cat['parent_external_id'],
                'name' => $cat['name'],
                'leaf' => $cat['leaf'] ? 1 : 0,
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($categoryRows) {
            foreach (array_chunk($categoryRows, 500) as $chunk) {
                StoreSupplierCatalogCategory::query()->upsert(
                    $chunk,
                    ['external_id'],
                    ['parent_external_id', 'name', 'leaf', 'synced_at', 'updated_at']
                );
            }
        });

        $includeIds = null;
        if ($allowedCategoryIds !== []) {
            $includeIds = $this->expandWithDescendants($allowedCategoryIds);
        }

        $products = $this->api->downloadProducts();
        $productRows = [];
        foreach ($products as $p) {
            if (! is_array($p) || empty($p['sku'])) {
                continue;
            }
            $categoryId = isset($p['category']) ? (int) $p['category'] : null;
            if ($includeIds !== null && ($categoryId === null || ! isset($includeIds[$categoryId]))) {
                continue;
            }

            $productRows[] = [
                'sku' => (int) $p['sku'],
                'category_external_id' => $categoryId,
                'name' => mb_substr(trim((string) ($p['name'] ?? '')), 0, 2000),
                'part' => $this->nullableString($p['part'] ?? null, 2000),
                'vendor' => $this->nullableString($p['vendor'] ?? null, 512),
                'rrp' => $this->nullableFloat($p['rrp'] ?? null),
                'warranty' => $this->nullableString($p['warranty'] ?? null, 64),
                'multiplicity' => max(0, (int) ($p['multiplicity'] ?? 1)),
                'barcodes' => $this->nullableString($p['barcodes'] ?? null, 4000),
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($productRows, $includeIds) {
            if ($includeIds !== null) {
                // Частичный sync: обновляем только выбранные категории
                foreach (array_chunk($productRows, 500) as $chunk) {
                    StoreSupplierCatalogProduct::query()->upsert(
                        $chunk,
                        ['sku'],
                        ['category_external_id', 'name', 'part', 'vendor', 'rrp', 'warranty', 'multiplicity', 'barcodes', 'synced_at', 'updated_at']
                    );
                }
            } else {
                StoreSupplierCatalogProduct::query()->delete();
                foreach (array_chunk($productRows, 500) as $chunk) {
                    StoreSupplierCatalogProduct::query()->insert($chunk);
                }
            }
        });

        return [
            'categories' => count($categoryRows),
            'products' => count($productRows),
        ];
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
