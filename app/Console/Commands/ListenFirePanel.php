<?php

namespace App\Console\Commands;

use App\Models\FirePanel;
use App\Services\Fire\FireAlarmService;
use App\Services\Fire\OrionPanelClient;
use Illuminate\Console\Command;

class ListenFirePanel extends Command
{
    protected $signature = 'fire:listen';

    protected $description = 'Читает события С2000М через С2000-Ethernet и держит блокировку комнат';

    public function handle(OrionPanelClient $client, FireAlarmService $alarms): int
    {
        $until = microtime(true) + 55;
        while (microtime(true) < $until) {
            $panels = FirePanel::query()->where('enabled', true)->whereNotNull('host')->get();
            foreach ($panels as $panel) {
                $client->poll($panel, $alarms);
            }
            $left = $until - microtime(true);
            if ($left <= 0) {
                break;
            }
            usleep((int) min(2_000_000, $left * 1_000_000));
        }

        return self::SUCCESS;
    }
}
