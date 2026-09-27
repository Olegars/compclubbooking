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
        $outcome['details'] = array_map(
            fn ($line) => mb_substr((string) $line, 0, 400),
            array_values(array_filter(
                is_array($outcome['details'] ?? null) ? $outcome['details'] : [],
                fn ($line) => is_string($line) || is_numeric($line),
            )),
        );
        try {
            Cache::put($tests->phpunitResultCacheKey($id), $outcome, 1800);
        } catch (\Throwable $e) {
            $outcome['details'] = array_merge(array_slice($outcome['details'], 0, 6), [
                'cache: '.mb_substr($e->getMessage(), 0, 240),
            ]);
            try {
                Cache::put($tests->phpunitResultCacheKey($id), $outcome, 1800);
            } catch (\Throwable) {
                $this->error($e->getMessage());
            }
        }

        return ($outcome['status'] ?? '') === 'pass' ? self::SUCCESS : self::FAILURE;
    }
}
