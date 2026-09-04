<?php

namespace App\Console\Commands;

use App\Jobs\GenerateStoreAvitoAdsJob;
use App\Models\StoreAvitoSetting;
use App\Services\StoreAvito\StoreAvitoAdGenerator;
use Illuminate\Console\Command;

class GenerateStoreAvitoAds extends Command
{
    protected $signature = 'store:generate-avito-ads
                            {--count= : Сколько объявлений}
                            {--sync : Выполнить сразу, без очереди}
                            {--force : Даже если автогенерация выключена}';

    protected $description = 'Сгенерировать пачку уникальных объявлений Avito (ПК) из каталога магазина';

    public function handle(StoreAvitoAdGenerator $generator): int
    {
        $count = $this->option('count');
        $count = $count !== null && $count !== '' ? (int) $count : null;
        $force = (bool) $this->option('force');
        $settings = StoreAvitoSetting::current();

        if (! $force && ! $settings->enabled) {
            $this->warn('Автогенерация Avito выключена (админка Магазин → Avito).');

            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $result = $generator->generate($count);
            $this->info('Создано: '.$result['created'].', пропущено: '.$result['skipped'].', размечено: '.$result['enriched']);
            if ($result['error']) {
                $this->warn($result['error']);
            }

            return self::SUCCESS;
        }

        GenerateStoreAvitoAdsJob::dispatch($count, $force);
        $this->info('Задача поставлена в очередь.');

        return self::SUCCESS;
    }
}
