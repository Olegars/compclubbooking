<?php

namespace App\Console\Commands;

use App\Services\UserFeatureTelemetry;
use Illuminate\Console\Command;

class PruneUserFeatureEvents extends Command
{
    protected $signature = 'telemetry:prune {--days=90 : Удалить сырые события старше N дней}';

    protected $description = 'Удалить сырые события фич старше срока хранения';

    public function handle(UserFeatureTelemetry $telemetry): int
    {
        $deleted = $telemetry->pruneOlderThan((int) $this->option('days'));
        $this->info('Удалено событий: '.$deleted);

        return self::SUCCESS;
    }
}
