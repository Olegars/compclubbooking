<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('reactor:check-quality')->everyMinute();
Schedule::command('reactor:update-statuses')->everyMinute()->withoutOverlapping();
Schedule::command('reactor:check-reviews')->dailyAt('10:00')->withoutOverlapping();
Schedule::command('reactor:sync-payments')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('store:sync-supplier-catalog')
    ->dailyAt('09:00')
    ->timezone('Europe/Moscow')
    ->withoutOverlapping(180)
    ->appendOutputTo(storage_path('logs/catalog-sync.log'));
Schedule::command('store:classify-cases')
    ->dailyAt('09:40')
    ->timezone('Europe/Moscow')
    ->withoutOverlapping(120)
    ->appendOutputTo(storage_path('logs/catalog-cases.log'));
Schedule::command('store:classify-catalog-parts')
    ->dailyAt('09:50')
    ->timezone('Europe/Moscow')
    ->withoutOverlapping(180)
    ->appendOutputTo(storage_path('logs/catalog-parts.log'));
Schedule::command('store:sync-avito-dicts')
    ->dailyAt('03:20')
    ->timezone('Europe/Moscow')
    ->withoutOverlapping(120)
    ->when(function () {
        if (! \Illuminate\Support\Facades\Schema::hasTable('store_avito_settings')) {
            return false;
        }
        $row = \Illuminate\Support\Facades\DB::table('store_avito_settings')->orderBy('id')->first();

        return $row && filled($row->client_id) && filled($row->client_secret);
    })
    ->appendOutputTo(storage_path('logs/avito-dicts.log'));
Schedule::command('store:sync-avito-dicts')
    ->everyMinute()
    ->timezone('Europe/Moscow')
    ->withoutOverlapping(120)
    ->when(function () {
        if (! \Illuminate\Support\Facades\Schema::hasTable('store_avito_settings')) {
            return false;
        }
        if (! \Illuminate\Support\Facades\Schema::hasColumn('store_avito_settings', 'last_dict_sync_result')) {
            return false;
        }
        $raw = \Illuminate\Support\Facades\DB::table('store_avito_settings')->orderBy('id')->value('last_dict_sync_result');
        $data = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($data)) {
            return false;
        }

        return ($data['status'] ?? '') === 'running' && ! empty($data['queued']);
    })
    ->appendOutputTo(storage_path('logs/avito-dicts.log'));
Schedule::command('store:generate-avito-ads --sync')
    ->hourly()
    ->timezone('Europe/Moscow')
    ->withoutOverlapping(50)
    ->appendOutputTo(storage_path('logs/avito-ads.log'));
Schedule::command('store:generate-avito-ads --sync --force')
    ->everyMinute()
    ->timezone('Europe/Moscow')
    ->withoutOverlapping(50)
    ->when(function () {
        if (! \Illuminate\Support\Facades\Schema::hasTable('store_avito_settings')) {
            return false;
        }
        $raw = \Illuminate\Support\Facades\DB::table('store_avito_settings')->orderBy('id')->value('last_generate_result');
        $data = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($data)) {
            return false;
        }

        return ($data['status'] ?? '') === 'running' && ! empty($data['queued']);
    })
    ->appendOutputTo(storage_path('logs/avito-ads.log'));
