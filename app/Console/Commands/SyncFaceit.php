<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\FaceitIdentity;
use App\Services\ClubFeatureService;
use App\Services\Faceit\FaceitIdentityService;
use App\Services\Faceit\FaceitRateLimited;
use Illuminate\Console\Command;

class SyncFaceit extends Command
{
    protected $signature = 'reactor:sync-faceit';

    protected $description = 'Кэш Elo FACEIT: сидящие чаще, остальные раз в 12 часов';

    public function handle(FaceitIdentityService $faceit, ClubFeatureService $features): int
    {
        $seated = Booking::query()->where('status', 'active')->pluck('user_id')->all();
        $rows = FaceitIdentity::query()->with('user')->orderBy('id')->get();
        $done = 0;
        foreach ($rows as $row) {
            if (! $row->user) {
                continue;
            }
            $clubId = $faceit->clubIdForUser($row->user);
            if ($faceit->mode($clubId) === 'off') {
                continue;
            }
            $minutes = in_array($row->user_id, $seated, true)
                ? max(5, $features->int($clubId, 'faceit', 'sync_seated_minutes', 15))
                : 12 * 60;
            if ($row->synced_at && $row->synced_at->gt(now()->subMinutes($minutes))) {
                continue;
            }
            try {
                $faceit->sync($row);
                $done++;
            } catch (FaceitRateLimited) {
                $this->warn('FACEIT 429, кэш не затираем');

                return self::SUCCESS;
            } catch (\Throwable $e) {
                $this->error($row->faceit_player_id.': '.$e->getMessage());
            }
        }
        $this->info('Синхронизировано: '.$done);

        return self::SUCCESS;
    }
}
