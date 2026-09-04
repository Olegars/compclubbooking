<?php

namespace App\Console\Commands;

use App\Services\StoreSupplierCatalogSyncService;
use Illuminate\Console\Command;

class SyncStoreSupplierCatalog extends Command
{
    protected $signature = 'store:sync-supplier-catalog
                            {--prices-only : Только обновить цены/остатки (get_active_products)}
                            {--skip-prices : Синк справочника без цен}
                            {--skip-cases : Не размечать корпуса через DeepSeek}
                            {--skip-parts : Не размечать CPU/GPU/ОЗУ/SSD/платы/БП через DeepSeek}';

    protected $description = 'Синхронизация каталога поставщика (QuickFox) и цен в локальный кэш';

    public function handle(StoreSupplierCatalogSyncService $sync): int
    {
        try {
            if ($this->option('prices-only')) {
                $priced = $sync->syncPrices();
                $this->info("Обновлено цен: {$priced}");

                return self::SUCCESS;
            }

            $result = $sync->sync(withPrices: ! $this->option('skip-prices'));
            $this->info("Категории: {$result['categories']}, товары: {$result['products']}, с ценой: {$result['priced']}");

            if (! $this->option('skip-cases')) {
                $enrichment = app(\App\Services\StoreCaseCatalogEnrichmentService::class);
                if (! $enrichment->isConfigured()) {
                    $this->warn('LLM API-ключ не задан (админка/.env) — корпуса не размечены.');
                } else {
                    $this->info('Разметка корпусов (DeepSeek)…');
                    $cases = $enrichment->classifyAllCases(force: false);
                    $this->info("Корпуса: всего {$cases['total']}, размечено {$cases['classified']}");
                }
            }

            if (! $this->option('skip-parts')) {
                $parts = app(\App\Services\StoreAvito\StoreAvitoCatalogAttrService::class);
                if (! $parts->isConfigured()) {
                    $this->warn('LLM API-ключ не задан — комплектующие (CPU/GPU/ОЗУ/SSD/плата/БП) не размечены.');
                } else {
                    $this->info('Разметка комплектующих (DeepSeek)…');
                    $attrs = $parts->classifyAll(force: false);
                    $this->info("Комплектующие: всего {$attrs['total']}, ждали {$attrs['pending_before']}, записано {$attrs['classified']}");
                }
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
