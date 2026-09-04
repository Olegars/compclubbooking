<?php

namespace App\Services\StoreAvito;

use App\Models\StoreAvitoAd;
use App\Models\StoreAvitoSetting;
use Illuminate\Support\Facades\Cache;

class StoreAvitoAdGenerator
{
    public function __construct(
        private readonly StoreAvitoCatalogAttrService $attrs,
        private readonly StoreAvitoBuildComposer $composer,
        private readonly StoreAvitoPricer $pricer,
        private readonly StoreAvitoCopywriter $copywriter,
        private readonly StoreAvitoDictMatcher $matcher,
        private readonly StoreAvitoDictSyncService $dicts,
    ) {}

    /**
     * @return array{created:int, skipped:int, enriched:int, error:?string}
     */
    public function generate(?int $count = null, bool $enrich = true): array
    {
        @set_time_limit(0);
        $lock = Cache::lock('store-avito-generate', 2400);
        if (! $lock->get()) {
            return ['created' => 0, 'skipped' => 0, 'enriched' => 0, 'error' => 'already running'];
        }

        try {
            return $this->generateLocked($count, $enrich);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * @return array{created:int, skipped:int, enriched:int, error:?string}
     */
    private function generateLocked(?int $count, bool $enrich): array
    {
        $settings = StoreAvitoSetting::current();
        $count ??= max(1, (int) $settings->ads_per_hour);
        $error = null;
        $enriched = 0;
        $settings->forceFill([
            'last_error' => null,
            'last_generate_result' => [
                'status' => 'running',
                'count' => $count,
                'started_at' => now()->toIso8601String(),
            ],
        ])->save();

        if ($enrich) {
            if (filled($settings->client_id) && filled($settings->client_secret) && ! $this->matcher->hasCatalog('ModelVideocard')) {
                try {
                    $this->dicts->sync($settings);
                    $settings->refresh();
                } catch (\Throwable $e) {
                    $error = $error ?: $e->getMessage();
                }
            }
            try {
                $enriched = $this->attrs->enrichPool();
            } catch (\Throwable $e) {
                $error = $error ?: $e->getMessage();
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
            'status' => $error ? 'error' : 'ok',
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
