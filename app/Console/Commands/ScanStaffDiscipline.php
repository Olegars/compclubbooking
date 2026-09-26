<?php

namespace App\Console\Commands;

use App\Services\StaffEdoService;
use Illuminate\Console\Command;

class ScanStaffDiscipline extends Command
{
    protected $signature = 'staff:edo-scan';

    protected $description = 'Невыход на слот, оставление смены и истечение срока объяснений (ст. 193 ТК РФ)';

    public function handle(StaffEdoService $edo): int
    {
        $result = $edo->scan();
        $this->info(sprintf(
            'Невыход: %d, оставление: %d, срок истёк: %d',
            $result['absences'],
            $result['abandonments'],
            $result['expired']
        ));

        return self::SUCCESS;
    }
}
