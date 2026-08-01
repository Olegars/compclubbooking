<?php

namespace App\Services\Fan;

use App\Models\Booking;
use App\Models\Computer;
use App\Models\ComputerThermal;
use App\Models\SpaceFan;
use App\Services\Fan\Drivers\FanActuatorResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FanControlService
{
    public function __construct(
        private readonly FanActuatorResolver $actuators,
    ) {
    }

    public function reconcileForComputer(int $computerId): ?SpaceFan
    {
        $computer = Computer::query()->find($computerId);
        if (! $computer || ! $computer->space_id) {
            return null;
        }

        return $this->reconcileForSpace((int) $computer->space_id, (int) $computer->club_id);
    }

    public function reconcileForSpace(int $spaceId, ?int $clubId = null): ?SpaceFan
    {
        return DB::transaction(function () use ($spaceId, $clubId) {
            /** @var SpaceFan|null $fan */
            $fan = SpaceFan::query()
                ->where('space_id', $spaceId)
                ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
                ->lockForUpdate()
                ->first();

            if (! $fan) {
                return null;
            }

            $fan->loadMissing('relayBoard');
            $board = $fan->relayBoard;
            if (! $board || ! $board->is_active) {
                $fan->last_error = 'Relay board missing or inactive';
                $fan->save();

                return $fan;
            }

            if ((int) $board->club_id !== (int) $fan->club_id) {
                $fan->last_error = 'Relay board club_id mismatch';
                $fan->save();

                return $fan;
            }

            $desired = $this->computeDesiredPower($fan);
            $fan->desired_power = $desired;

            if ((int) $fan->applied_power === $desired && $fan->last_error === null) {
                $fan->save();

                return $fan;
            }

            try {
                $this->actuators->resolve($board)->apply($board, (int) $fan->channel, $desired);
                $fan->applied_power = $desired;
                $fan->last_error = null;
                $fan->last_applied_at = now();
            } catch (Throwable $e) {
                $fan->last_error = $e->getMessage();
                Log::warning('[FAN] apply failed', [
                    'space_fan_id' => $fan->id,
                    'space_id' => $fan->space_id,
                    'channel' => $fan->channel,
                    'desired' => $desired,
                    'error' => $e->getMessage(),
                ]);
            }

            $fan->save();

            return $fan;
        });
    }

    /**
     * @param  'on'|'off'|'auto'  $action
     */
    public function setManualModeForComputer(int $computerId, string $action): ?SpaceFan
    {
        $computer = Computer::query()->find($computerId);
        if (! $computer || ! $computer->space_id) {
            return null;
        }

        $mode = match ($action) {
            'on' => SpaceFan::MODE_FORCE_ON,
            'off' => SpaceFan::MODE_FORCE_OFF,
            'auto' => SpaceFan::MODE_AUTO,
            default => throw new \InvalidArgumentException("Unknown fan action: {$action}"),
        };

        SpaceFan::query()
            ->where('space_id', $computer->space_id)
            ->where('club_id', $computer->club_id)
            ->update(['manual_mode' => $mode]);

        return $this->reconcileForSpace((int) $computer->space_id, (int) $computer->club_id);
    }

    public function reportThermal(int $computerId, float $cpuC): ?SpaceFan
    {
        $computer = Computer::query()->find($computerId);
        if (! $computer) {
            return null;
        }

        $fan = $computer->space_id
            ? SpaceFan::query()
                ->where('space_id', $computer->space_id)
                ->where('club_id', $computer->club_id)
                ->first()
            : null;

        $onC = (float) ($fan?->thermal_on_c ?? config('fan.thermal_on_c', 75));
        $offC = (float) ($fan?->thermal_off_c ?? config('fan.thermal_off_c', 65));

        $existing = ComputerThermal::query()->where('computer_id', $computer->id)->first();
        $wasHot = (bool) ($existing?->is_hot ?? false);
        $isHot = $wasHot
            ? $cpuC > $offC
            : $cpuC >= $onC;

        ComputerThermal::query()->updateOrCreate(
            ['computer_id' => $computer->id],
            [
                'club_id' => $computer->club_id,
                'cpu_c' => $cpuC,
                'is_hot' => $isHot,
                'reported_at' => now(),
            ]
        );

        if (! $computer->space_id) {
            return null;
        }

        return $this->reconcileForSpace((int) $computer->space_id, (int) $computer->club_id);
    }

    public function stateForComputer(int $computerId): array
    {
        $computer = Computer::query()->find($computerId);
        if (! $computer || ! $computer->space_id) {
            return [
                'available' => false,
                'reason' => 'no_space',
            ];
        }

        $fan = SpaceFan::query()
            ->where('space_id', $computer->space_id)
            ->where('club_id', $computer->club_id)
            ->first();

        if (! $fan) {
            return [
                'available' => false,
                'reason' => 'no_fan',
                'space_id' => (int) $computer->space_id,
                'club_id' => (int) $computer->club_id,
            ];
        }

        return [
            'available' => true,
            'club_id' => (int) $fan->club_id,
            'space_id' => (int) $fan->space_id,
            'manual_mode' => $fan->manual_mode,
            'desired_power' => (int) $fan->desired_power,
            'applied_power' => (int) $fan->applied_power,
            'is_on' => $fan->isOn(),
            'last_error' => $fan->last_error,
            'reasons' => $this->currentReasons($fan),
        ];
    }

    /**
     * @return array{session:bool,thermal:bool,manual_mode:string}
     */
    public function currentReasons(SpaceFan $fan): array
    {
        return [
            'session' => $this->spaceHasActiveSession($fan),
            'thermal' => $this->spaceHasThermalHot($fan),
            'manual_mode' => $fan->manual_mode,
        ];
    }

    private function computeDesiredPower(SpaceFan $fan): int
    {
        $onPower = max(1, min(100, (int) ($fan->default_on_power ?: config('fan.default_on_power', 100))));

        if ($fan->manual_mode === SpaceFan::MODE_FORCE_OFF) {
            return 0;
        }

        if ($fan->manual_mode === SpaceFan::MODE_FORCE_ON) {
            return $onPower;
        }

        if ($this->spaceHasActiveSession($fan) || $this->spaceHasThermalHot($fan)) {
            return $onPower;
        }

        return 0;
    }

    private function spaceHasActiveSession(SpaceFan $fan): bool
    {
        $computerIds = Computer::query()
            ->where('space_id', $fan->space_id)
            ->where('club_id', $fan->club_id)
            ->pluck('id');

        if ($computerIds->isEmpty()) {
            return false;
        }

        return Booking::query()
            ->where('status', 'active')
            ->whereIn('computer_id', $computerIds)
            ->exists();
    }

    private function spaceHasThermalHot(SpaceFan $fan): bool
    {
        $computerIds = Computer::query()
            ->where('space_id', $fan->space_id)
            ->where('club_id', $fan->club_id)
            ->pluck('id');

        if ($computerIds->isEmpty()) {
            return false;
        }

        return ComputerThermal::query()
            ->whereIn('computer_id', $computerIds)
            ->where('is_hot', true)
            ->exists();
    }
}
