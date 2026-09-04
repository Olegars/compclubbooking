<?php

namespace App\Services\StoreAvito;

use App\Models\StoreAvitoSetting;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Запуск генерации вне HTTP: иначе nginx отдаёт 504, пока DeepSeek пишет 20 объявлений.
 */
class StoreAvitoGenerateLauncher
{
    public const STALE_AFTER_MINUTES = 50;

    public function isRunning(?StoreAvitoSetting $settings = null): bool
    {
        $settings ??= StoreAvitoSetting::current();
        $result = is_array($settings->last_generate_result) ? $settings->last_generate_result : [];
        if (($result['status'] ?? '') !== 'running') {
            return false;
        }
        $started = $result['started_at'] ?? null;
        if (! is_string($started) || $started === '') {
            return true;
        }
        try {
            return \Illuminate\Support\Carbon::parse($started)
                ->gt(now()->subMinutes(self::STALE_AFTER_MINUTES));
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @return array{ok:bool, already:bool, message:string}
     */
    public function launch(?int $count = null, bool $force = true): array
    {
        $settings = StoreAvitoSetting::current();
        if ($this->isRunning($settings)) {
            return [
                'ok' => true,
                'already' => true,
                'message' => 'Генерация уже идёт. Обновите страницу через минуту.',
            ];
        }

        $count = max(1, min(50, $count ?? (int) $settings->ads_per_hour));
        $settings->forceFill([
            'last_error' => null,
            'last_generate_result' => [
                'status' => 'running',
                'queued' => true,
                'count' => $count,
                'started_at' => now()->toIso8601String(),
            ],
        ])->save();

        if (app()->environment('testing')) {
            return ['ok' => true, 'already' => false, 'message' => 'Генерация запущена в фоне.'];
        }

        $php = (new PhpExecutableFinder)->find() ?: 'php';
        $artisan = base_path('artisan');
        $log = storage_path('logs/avito-ads.log');
        $cmd = sprintf(
            '%s %s store:generate-avito-ads --sync --count=%d%s',
            escapeshellarg($php),
            escapeshellarg($artisan),
            $count,
            $force ? ' --force' : ''
        );

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                pclose(popen('start /B '.$cmd.' >> '.escapeshellarg($log).' 2>&1', 'r'));
            } else {
                exec('nohup '.$cmd.' >> '.escapeshellarg($log).' 2>&1 &');
            }
        } catch (\Throwable $e) {
            $settings->forceFill([
                'last_error' => $e->getMessage(),
                'last_generate_result' => ['status' => 'error', 'error' => $e->getMessage()],
            ])->save();

            return ['ok' => false, 'already' => false, 'message' => 'Не удалось запустить фон: '.$e->getMessage()];
        }

        return ['ok' => true, 'already' => false, 'message' => 'Генерация запущена в фоне. Объявления появятся через 1–3 минуты.'];
    }
}
