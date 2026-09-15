<?php

namespace App\Console\Commands;

use App\Services\OwnerSystemTestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RunOwnerPhpunit extends Command
{
    protected $signature = 'owner:run-phpunit {id}';

    protected $description = 'Фоновый php artisan test для /admin/system-tests — не держит php-fpm, иначе nginx даёт 504';

    public function handle(OwnerSystemTestService $tests): int
    {
        $id = (string) $this->argument('id');
        if (! str_starts_with($id, 'phpunit:')) {
            $this->error('id должен начинаться с phpunit:');

            return self::FAILURE;
        }

        $started = microtime(true);
        $outcome = $tests->runPhpunitSync($id);
        $outcome['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
        Cache::put($tests->phpunitResultCacheKey($id), $outcome, 1800);

        return ($outcome['status'] ?? '') === 'pass' ? self::SUCCESS : self::FAILURE;
    }
}
