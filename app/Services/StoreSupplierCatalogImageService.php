<?php

namespace App\Services;

use App\Models\StoreSupplierCatalogProduct;
use Illuminate\Support\Facades\Log;

class StoreSupplierCatalogImageService
{
    public function __construct(private QuickFoxApiClient $api) {}

    /**
     * @param  list<int|string|null>  $skus
     * @return array<int, string|null> sku => proxy url
     */
    public function urlsForSkus(array $skus): array
    {
        $skus = array_values(array_unique(array_map('intval', $skus)));
        $skus = array_values(array_filter($skus, fn ($s) => $s > 0));
        if ($skus === []) {
            return [];
        }

        $payload = array_map(fn (int $sku) => ['sku' => $sku, 'has_image' => false], $skus);
        $attached = $this->attachToSearchResults($payload);

        $map = [];
        foreach ($attached as $row) {
            $map[(int) $row['sku']] = $row['image_url'] ?? null;
        }

        return $map;
    }

    /**
     * Все пути картинок sku (из БД или с API).
     *
     * @return list<string>
     */
    public function pathsForSku(int $sku, bool $forceRefresh = false): array
    {
        if ($sku <= 0) {
            return [];
        }

        $product = StoreSupplierCatalogProduct::query()->where('sku', $sku)->first();
        if (! $product) {
            return [];
        }

        $existing = is_array($product->image_paths) ? array_values(array_filter($product->image_paths)) : [];
        if (! $forceRefresh && $existing !== []) {
            return $existing;
        }

        if (! $forceRefresh && filled($product->image_path) && $product->image_synced_at !== null && $existing === []) {
            // Старая запись только с одной картинкой — один раз дотянем полный список.
        }

        if (! $this->api->isConfigured()) {
            return filled($product->image_path) ? [(string) $product->image_path] : $existing;
        }

        try {
            $lists = $this->api->getProductImagePathLists([$sku]);
            $paths = $lists[$sku] ?? [];
            if ($paths === [] && filled($product->image_path)) {
                $paths = [(string) $product->image_path];
            }
            $now = now();
            $product->update([
                'image_path' => $paths[0] ?? null,
                'image_paths' => $paths !== [] ? $paths : null,
                'has_image' => $paths !== [],
                'image_synced_at' => $now,
            ]);

            return $paths;
        } catch (\Throwable $e) {
            Log::warning('QuickFox images list: '.$e->getMessage());

            return filled($product->image_path) ? [(string) $product->image_path] : $existing;
        }
    }

    /**
     * Proxy-URL всех картинок sku.
     *
     * @return list<string>
     */
    public function proxyUrlsForSku(int $sku): array
    {
        $paths = $this->pathsForSku($sku);
        if ($paths === []) {
            return [];
        }

        $urls = [];
        foreach (array_keys($paths) as $i) {
            $urls[] = route('admin.store.estimates.catalog-image', ['sku' => $sku, 'i' => $i]);
        }

        return $urls;
    }

    /**
     * Подтянуть пути картинок в БД (если ещё нет) и вернуть proxy-URL для выдачи в UI.
     *
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    public function attachToSearchResults(array $products): array
    {
        if ($products === [] || ! $this->api->isConfigured()) {
            return array_map(function (array $p) {
                $p['image_url'] = null;
                $p['has_image'] = (bool) ($p['has_image'] ?? false);

                return $p;
            }, $products);
        }

        $skus = array_values(array_unique(array_map(fn ($p) => (int) ($p['sku'] ?? 0), $products)));
        $skus = array_values(array_filter($skus, fn ($s) => $s > 0));

        $rows = StoreSupplierCatalogProduct::query()
            ->whereIn('sku', $skus)
            ->get(['sku', 'has_image', 'image_path', 'image_paths', 'image_synced_at'])
            ->keyBy('sku');

        $needFetch = [];
        foreach ($skus as $sku) {
            $row = $rows->get($sku);
            if (! $row) {
                continue;
            }
            $hasPaths = is_array($row->image_paths) && $row->image_paths !== [];
            if ((! $hasPaths && blank($row->image_path)) && ($row->image_synced_at === null || $row->has_image)) {
                $needFetch[] = $sku;
            }
        }

        if ($needFetch !== []) {
            try {
                $lists = $this->api->getProductImagePathLists($needFetch);
                $now = now();
                foreach ($needFetch as $sku) {
                    $paths = $lists[$sku] ?? [];
                    $path = $paths[0] ?? null;
                    StoreSupplierCatalogProduct::query()
                        ->where('sku', $sku)
                        ->update([
                            'image_path' => $path,
                            'image_paths' => $paths !== [] ? $paths : null,
                            'has_image' => $path !== null,
                            'image_synced_at' => $now,
                            'updated_at' => $now,
                        ]);
                    if ($rows->has($sku)) {
                        $rows[$sku]->image_path = $path;
                        $rows[$sku]->image_paths = $paths !== [] ? $paths : null;
                        $rows[$sku]->has_image = $path !== null;
                        $rows[$sku]->image_synced_at = $now;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('QuickFox images: '.$e->getMessage());
            }
        }

        return array_map(function (array $p) use ($rows) {
            $sku = (int) ($p['sku'] ?? 0);
            $row = $rows->get($sku);
            $has = $row ? (bool) $row->has_image && filled($row->image_path) : false;
            $p['has_image'] = $has;
            $p['image_url'] = $has
                ? route('admin.store.estimates.catalog-image', ['sku' => $sku])
                : null;

            return $p;
        }, $products);
    }
}
