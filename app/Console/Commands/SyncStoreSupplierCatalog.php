<?php

namespace App\Console\Commands;

use App\Services\StoreSupplierCatalogSyncService;
use Illuminate\Console\Command;

class SyncStoreSupplierCatalog extends Command
{
    protected $signature = 'store:sync-supplier-catalog
                            {--prices-only : Только обновить цены/остатки (get_active_products)}
                            {--skip-prices : Синк справочника без цен}';

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

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
