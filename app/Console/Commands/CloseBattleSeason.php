<?php

namespace App\Console\Commands;

use App\Models\GsiAwardDedup;
use App\Models\UserIdentity;
use App\Services\BattlePassService;
use Illuminate\Console\Command;

class CloseBattleSeason extends Command
{
    protected $signature = 'reactor:close-battle-season';

    protected $description = 'Закрывает сезон пропуска по дате и чистит GSI-дедуп старше 48 часов';

    public function handle(BattlePassService $pass): int
    {
        $pass->rollSeasons();
        GsiAwardDedup::query()->where('created_at', '<', now()->subHours(48))->delete();
        UserIdentity::query()->whereNotNull('purge_after')->where('purge_after', '<', now())->delete();

        return self::SUCCESS;
    }
}
