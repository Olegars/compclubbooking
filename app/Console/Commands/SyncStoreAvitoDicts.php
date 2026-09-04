<?php

namespace App\Console\Commands;

use App\Models\StoreAvitoSetting;
use App\Services\StoreAvito\StoreAvitoDictSyncService;
use Illuminate\Console\Command;

class SyncStoreAvitoDicts extends Command
{
    protected $signature = 'store:sync-avito-dicts';

    protected $description = 'Скачать справочники XML Avito (системные блоки) через Autoload user-docs';

    public function handle(StoreAvitoDictSyncService $sync): int
    {
        $settings = StoreAvitoSetting::current();
        if (! filled($settings->client_id) || ! filled($settings->client_secret)) {
            $this->error('Задайте client_id и client_secret Avito в админке Магазин → Avito.');

            return self::FAILURE;
        }

        $this->info('Качаю справочники Avito…');
        $result = $sync->sync($settings);
        if (! $result['ok']) {
            $this->error($result['error'] ?: 'Ошибка синка справочников');

            return self::FAILURE;
        }

        $this->info('Категория: '.$result['slug']);
        foreach ($result['counts'] as $tag => $count) {
            $this->line(sprintf('  %-22s %d', $tag, $count));
        }

        return self::SUCCESS;
    }
}
