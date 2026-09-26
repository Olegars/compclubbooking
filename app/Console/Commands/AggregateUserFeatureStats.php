<?php

namespace App\Console\Commands;

use App\Services\UserFeatureTelemetry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AggregateUserFeatureStats extends Command
{
    protected $signature = 'telemetry:aggregate {--date= : День YYYY-MM-DD, по умолчанию вчера}';

    protected $description = 'Собрать суточные агрегаты осознанных действий гостей';

    public function handle(UserFeatureTelemetry $telemetry): int
    {
        $raw = $this->option('date');
        $date = is_string($raw) && $raw !== ''
            ? CarbonImmutable::parse($raw)->startOfDay()
            : CarbonImmutable::now()->subDay()->startOfDay();

        $rows = $telemetry->aggregateDate($date);
        $this->info($date->toDateString().': '.$rows);

        return self::SUCCESS;
    }
}
