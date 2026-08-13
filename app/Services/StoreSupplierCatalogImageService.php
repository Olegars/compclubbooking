<?php

namespace App\Services;

use App\Models\StoreSupplierCatalogProduct;
use Illuminate\Support\Facades\Log;

class StoreSupplierCatalogImageService
{
    public function __construct(private QuickFoxApiClient $api) {}

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
            ->get(['sku', 'has_image', 'image_path', 'image_synced_at'])
            ->keyBy('sku');

        $needFetch = [];
        foreach ($skus as $sku) {
            $row = $rows->get($sku);
            if (! $row) {
                continue;
            }
            // Ещё не пробовали тянуть картинку (или в каталоге есть флаг, а пути нет)
            if (blank($row->image_path) && ($row->image_synced_at === null || $row->has_image)) {
                $needFetch[] = $sku;
            }
        }

        if ($needFetch !== []) {
            try {
                $paths = $this->api->getProductImagePaths($needFetch);
                $now = now();
                foreach ($needFetch as $sku) {
                    $path = $paths[$sku] ?? null;
                    StoreSupplierCatalogProduct::query()
                        ->where('sku', $sku)
                        ->update([
                            'image_path' => $path,
                            'has_image' => $path !== null,
                            'image_synced_at' => $now,
                            'updated_at' => $now,
                        ]);
                    if ($rows->has($sku)) {
                        $rows[$sku]->image_path = $path;
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
