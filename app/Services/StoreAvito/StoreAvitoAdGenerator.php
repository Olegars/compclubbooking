<?php

namespace App\Services\StoreAvito;

use App\Models\StoreAvitoAd;
use App\Models\StoreAvitoSetting;

class StoreAvitoAdGenerator
{
    public function __construct(
        private readonly StoreAvitoCatalogAttrService $attrs,
        private readonly StoreAvitoBuildComposer $composer,
        private readonly StoreAvitoPricer $pricer,
        private readonly StoreAvitoCopywriter $copywriter,
    ) {}

    /**
     * @return array{created:int, skipped:int, enriched:int, error:?string}
     */
    public function generate(?int $count = null, bool $enrich = true): array
    {
        $settings = StoreAvitoSetting::current();
        $count ??= max(1, (int) $settings->ads_per_hour);
        $error = null;
        $enriched = 0;

        if ($enrich) {
            try {
                $enriched = $this->attrs->enrichPool();
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $builds = $this->composer->compose($count, $settings);
        $created = 0;
        foreach ($builds as $build) {
            $this->persist($settings, $build);
            $created++;
        }

        $this->trimActive((int) $settings->keep_active);

        $result = [
            'created' => $created,
            'skipped' => max(0, $count - $created),
            'enriched' => $enriched,
            'error' => $error,
        ];

        $settings->forceFill([
            'last_generated_at' => now(),
            'last_generate_result' => $result,
            'last_error' => $error,
        ])->save();

        return $result;
    }

    /**
     * @param  array{fingerprint:string, components:list<array<string,mixed>>, xml:array<string,string>}  $build
     */
    private function persist(StoreAvitoSetting $settings, array $build): StoreAvitoAd
    {
        $configId = $this->newConfigId();
        $parts = [];
        foreach ($build['components'] as $row) {
            $purchase = (float) ($row['purchase'] ?? 0);
            $parts[] = [
                'type' => $row['type'] ?? '',
                'sku' => $row['sku'] ?? 0,
                'name' => $row['name'] ?? '',
                'part' => $row['part'] ?? '',
                'purchase' => $purchase,
                'sale' => $this->pricer->saleOf($purchase, $settings),
            ];
        }
        $price = $this->pricer->quote($parts, $settings);
        $copy = $this->copywriter->write($configId, $parts, $price, $build['xml']);
        $images = $this->imageRefs($build['components']);

        return StoreAvitoAd::query()->create([
            'config_id' => $configId,
            'fingerprint' => $build['fingerprint'],
            'title' => $copy['title'],
            'description' => $copy['description'],
            'price' => $price,
            'components' => $parts,
            'xml' => $build['xml'],
            'images' => $images,
            'status' => 'active',
            'generated_at' => now(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $components
     * @return list<array{sku:int}>
     */
    private function imageRefs(array $components): array
    {
        $out = [];
        foreach ($components as $row) {
            if (! empty($row['has_image']) && ! empty($row['sku'])) {
                $out[] = ['sku' => (int) $row['sku']];
            }
        }

        return array_slice($out, 0, 8);
    }

    private function newConfigId(): string
    {
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        do {
            $id = substr(str_shuffle($letters), 0, 3).substr(str_shuffle($digits), 0, 5);
        } while (StoreAvitoAd::query()->where('config_id', $id)->exists());

        return $id;
    }

    private function trimActive(int $keep): void
    {
        $keep = max(20, $keep);
        $ids = StoreAvitoAd::query()
            ->active()
            ->orderByDesc('id')
            ->skip($keep)
            ->take(5000)
            ->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }

        StoreAvitoAd::query()->whereIn('id', $ids)->update(['status' => 'archived']);
    }
}
