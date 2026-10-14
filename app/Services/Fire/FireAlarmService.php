<?php

namespace App\Services\Fire;

use App\Models\Computer;
use App\Models\FireAlarm;
use App\Models\FireDetector;
use App\Models\FirePanel;
use App\Models\Space;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FireAlarmService
{
    public const SCREEN_LINE = 'ПОЖАР. Срочно покиньте комнату';

    public function note(int $clubId, int $deviceAddress, int $loopAddress, int $eventCode): void
    {
        if ($deviceAddress < 1 || $loopAddress < 1) {
            return;
        }

        $detector = FireDetector::query()
            ->where('club_id', $clubId)
            ->where('device_address', $deviceAddress)
            ->where('loop_address', $loopAddress)
            ->first();
        if (! $detector) {
            return;
        }

        $detector->last_code = $eventCode;
        $detector->last_seen_at = Carbon::now();

        if (OrionCodes::isSmoke($eventCode)) {
            $detector->smoke = true;
            $detector->save();
            $this->lockSpace($detector, $eventCode);

            return;
        }

        if (OrionCodes::isClear($eventCode) && ($eventCode !== 24 || $detector->smoke)) {
            $detector->smoke = false;
            $detector->save();
            $this->releaseSpaceIfClear((int) $detector->space_id);

            return;
        }

        $detector->save();
    }

    /**
     * Кнопка админа. Ставит сброс на пульт и не трогает блокировку сессий.
     */
    public function requestReset(int $clubId): FirePanel
    {
        $panel = FirePanel::query()->firstOrCreate(
            ['club_id' => $clubId],
            ['port' => 40000, 'panel_address' => 1, 'enabled' => false]
        );
        $panel->reset_pending = true;
        $panel->save();

        return $panel;
    }

    /**
     * @return array{active:bool,message:string,space:string}
     */
    public function screenForComputer(?Computer $computer): array
    {
        $idle = ['active' => false, 'message' => '', 'space' => ''];
        if (! $computer || ! $computer->space_id) {
            return $idle;
        }

        $alarm = FireAlarm::query()
            ->where('space_id', $computer->space_id)
            ->where('status', FireAlarm::STATUS_ACTIVE)
            ->first();
        if (! $alarm) {
            return $idle;
        }

        $space = Space::query()->find($computer->space_id);

        return [
            'active' => true,
            'message' => self::SCREEN_LINE,
            'space' => (string) ($space->name ?? ''),
        ];
    }

    /**
     * @return array{count:int,alarms:list<array{id:int,space:string,since:string}>}
     */
    public function liveForClub(?int $clubId): array
    {
        if (! $clubId) {
            return ['count' => 0, 'alarms' => []];
        }

        $rows = FireAlarm::query()
            ->with('space:id,name')
            ->where('club_id', $clubId)
            ->where('status', FireAlarm::STATUS_ACTIVE)
            ->orderBy('started_at')
            ->get();

        return [
            'count' => $rows->count(),
            'alarms' => $rows->map(fn (FireAlarm $alarm) => [
                'id' => (int) $alarm->id,
                'space' => (string) ($alarm->space->name ?? 'Комната'),
                'since' => optional($alarm->started_at)->format('H:i:s') ?? '',
            ])->all(),
        ];
    }

    private function lockSpace(FireDetector $detector, int $eventCode): void
    {
        DB::transaction(function () use ($detector, $eventCode) {
            $existing = FireAlarm::query()
                ->where('space_id', $detector->space_id)
                ->where('status', FireAlarm::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                $existing->last_code = $eventCode;
                $existing->detector_id = $detector->id;
                $existing->save();

                return;
            }

            FireAlarm::query()->create([
                'club_id' => $detector->club_id,
                'space_id' => $detector->space_id,
                'detector_id' => $detector->id,
                'status' => FireAlarm::STATUS_ACTIVE,
                'started_at' => Carbon::now(),
                'last_code' => $eventCode,
            ]);
        });
    }

    private function releaseSpaceIfClear(int $spaceId): void
    {
        $stillSmoking = FireDetector::query()
            ->where('space_id', $spaceId)
            ->where('smoke', true)
            ->exists();
        if ($stillSmoking) {
            return;
        }

        FireAlarm::query()
            ->where('space_id', $spaceId)
            ->where('status', FireAlarm::STATUS_ACTIVE)
            ->update([
                'status' => FireAlarm::STATUS_CLEARED,
                'cleared_at' => Carbon::now(),
            ]);
    }
}
