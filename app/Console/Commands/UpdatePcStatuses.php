<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\BookingSessionTimingService;
use App\Services\ComputerPowerService;
use App\Services\ComputerStatusService;

class UpdatePcStatuses extends Command
{
    protected $signature = 'reactor:update-statuses';
    protected $description = 'Обновление статусов ПК на основе активных бронирований';

    public function handle(
        BookingSessionTimingService $timing,
        ComputerStatusService $statuses,
        ComputerPowerService $power,
    ) {
        $noShows = $timing->cancelNoShows();
        if ($noShows > 0) {
            $this->info('No-show отменено: '.$noShows);
        }

        $closed = $timing->completeExpiredSessions();
        if ($closed > 0) {
            $this->info('Закрыто просроченных сессий: '.$closed);
        }

        // Страховка: статусы уже пишутся синхронно при старте/закрытии сессии,
        // здесь добираем расхождения (упавшие запросы, ручные правки в БД).
        $changed = $statuses->syncAll();
        if ($changed > 0) {
            $this->info('Обновлено узлов: '.$changed);
        }

        // Питание: desired on/off по букингам ± warmup, WOL, stale → off/error.
        $powerChanged = $power->syncAll();
        if ($powerChanged > 0) {
            $this->info('Обновлено питание: '.$powerChanged);
        }

        return Command::SUCCESS;
    }
}
