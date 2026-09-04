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
        $startedAt = now()->toIso8601String();
        $this->mark($settings, 'compose', $count, $startedAt);

        if ($enrich) {
            try {
                $enriched = $this->attrs->enrichPool(useLlm: false);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $this->mark($settings, 'copy', $count, $startedAt);
        $builds = $this->composer->compose($count, $settings);
        $jobs = [];
        foreach ($builds as $build) {
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
            $jobs[] = [
                'config_id' => $configId,
                'fingerprint' => $build['fingerprint'],
                'store_avito_config_id' => $build['store_avito_config_id'] ?? null,
                'components' => $parts,
                'images' => $this->imageRefs($build['components']),
                'xml' => $build['xml'],
                'price' => $this->pricer->quote($parts, $settings),
            ];
        }

        $copies = $this->copywriter->writeMany($jobs);
        $created = 0;
        foreach ($jobs as $job) {
            $copy = $copies[$job['config_id']] ?? $this->copywriter->fallback(
                $job['config_id'],
                $job['components'],
                $job['price'],
                $job['xml'],
                StoreAvitoSetting::configPhrase($job['config_id']),
            );
            StoreAvitoAd::query()->create([
                'config_id' => $job['config_id'],
                'fingerprint' => $job['fingerprint'],
                'store_avito_config_id' => $job['store_avito_config_id'],
                'title' => $copy['title'],
                'description' => $copy['description'],
                'price' => $job['price'],
                'components' => $job['components'],
                'xml' => $job['xml'],
                'images' => $job['images'],
                'status' => 'active',
                'generated_at' => now(),
            ]);
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

    private function mark(StoreAvitoSetting $settings, string $stage, int $count, string $startedAt): void
    {
        $settings->forceFill([
            'last_generate_result' => [
                'status' => 'running',
                'stage' => $stage,
                'count' => $count,
                'started_at' => $startedAt,
            ],
        ])->save();
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
