<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\BookingSeatTransferService;
use App\Services\BookingSessionTimingService;
use App\Services\ComputerPowerService;
use App\Services\ComputerStatusService;
use App\Services\FiscalService;

class UpdatePcStatuses extends Command
{
    protected $signature = 'reactor:update-statuses';
    protected $description = 'Обновление статусов ПК на основе активных бронирований';

    public function handle(
        BookingSessionTimingService $timing,
        BookingSeatTransferService $transfers,
        ComputerStatusService $statuses,
        ComputerPowerService $power,
        FiscalService $fiscal,
    ) {
        $noShows = $timing->cancelNoShows();
        if ($noShows > 0) {
            $this->info('No-show отменено: '.$noShows);
        }

        $closed = $timing->completeExpiredSessions();
        if ($closed > 0) {
            $this->info('Закрыто просроченных сессий: '.$closed);
        }

        $reclaimed = $transfers->reclaimAbandonedTransfers();
        if ($reclaimed > 0) {
            $this->info('Откат незавершённых пересадок: '.$reclaimed);
        }

        $orphaned = $fiscal->settleOrphanedDeferredBookings();
        if ($orphaned > 0) {
            $this->info('Закрыто отложенных чеков (no-show/просрочка): '.$orphaned);
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

        $released = app(\App\Services\PreSessionOrderService::class)->releaseDueOrders();
        if ($released > 0) {
            $this->info('Заказы к сессии в очереди: '.$released);
        }

        return Command::SUCCESS;
    }
}
