<?php

namespace App\Console\Commands;

use App\Services\StaffBonusService;
use Illuminate\Console\Command;

class CloseStaffBonusPeriods extends Command
{
    protected $signature = 'staff:bonus-close';

    protected $description = 'Закрыть месяц и квартал баллов эффективности, если вчера был последний день периода';

    public function handle(StaffBonusService $bonus): int
    {
        $result = $bonus->closeDuePeriods();
        $this->info(sprintf(
            'Месяцев закрыто: %d, кварталов: %d',
            $result['months'],
            $result['quarters']
        ));

        return self::SUCCESS;
    }
}
