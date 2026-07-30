<?php

namespace App\Console\Commands;

use App\Services\ReviewBonusService;
use Illuminate\Console\Command;

class CheckReviewClaims extends Command
{
    protected $signature = 'reactor:check-reviews';

    protected $description = 'Сверка pending-заявок на бонус с отзывами Яндекс.Карт / 2ГИС';

    public function handle(ReviewBonusService $service): int
    {
        $this->info('Проверка отзывов…');

        $result = $service->processPendingClaims();

        $this->info(sprintf(
            'Загружено отзывов: %d · синхронизировано: %d · совпадений: %d · истекло: %d',
            $result['fetched'],
            $result['synced'] ?? 0,
            $result['matched'],
            $result['expired']
        ));

        return self::SUCCESS;
    }
}
