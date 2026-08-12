<?php

namespace App\Console\Commands;

use App\Services\StoreSupplierCatalogSyncService;
use Illuminate\Console\Command;

class SyncStoreSupplierCatalog extends Command
{
    protected $signature = 'store:sync-supplier-catalog';

    protected $description = 'Синхронизация каталога поставщика (QuickFox) в локальный кэш';

    public function handle(StoreSupplierCatalogSyncService $sync): int
    {
        try {
            $result = $sync->sync();
            $this->info("Категории: {$result['categories']}, товары: {$result['products']}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
