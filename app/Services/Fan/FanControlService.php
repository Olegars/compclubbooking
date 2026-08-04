<?php

namespace App\Services\Fan;

use App\Models\Booking;
use App\Models\Computer;
use App\Models\ComputerThermal;
use App\Models\RelayBoard;
use App\Models\SpaceFan;
use App\Services\ComputerPowerService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FanControlService
{
    /**
     * Suggested desired power for shell (no HTTP to relay from cloud).
     */
    public function reconcileForComputer(int $computerId): ?SpaceFan
    {
        $computer = Computer::query()->find($computerId);
        if (! $computer || ! $computer->space_id) {
            return null;
        }

        return $this->reconcileForSpace((int) $computer->space_id, (int) $computer->club_id);
    }

    /**
     * Refresh desired_power from room facts. Does not actuate the relay.
     */
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
                $fan->last_error = $fan->last_error ?: 'Relay board missing or inactive';
                $fan->desired_power = $this->computeDesiredPower($fan);
                $fan->save();

                return $fan;
            }

            if ((int) $board->club_id !== (int) $fan->club_id) {
                $fan->last_error = 'Relay board club_id mismatch';
                $fan->desired_power = $this->computeDesiredPower($fan);
                $fan->save();

                return $fan;
            }

            $fan->desired_power = $this->computeDesiredPower($fan);
            $fan->save();

            return $fan;
        });
    }

    /**
     * @param  'on'|'off'|'auto'|'50'|'75'|'100'  $action
     * @return array{fan: ?SpaceFan, locked: bool, remaining_sec: int}
     */
    public function setManualModeForComputer(int $computerId, string $action): array
    {
        $computer = Computer::query()->find($computerId);
        if (! $computer || ! $computer->space_id) {
            return ['fan' => null, 'locked' => false, 'remaining_sec' => 0];
        }

        $forcedSpeed = null;
        $mode = match ($action) {
            'off' => SpaceFan::MODE_FORCE_OFF,
            'auto' => SpaceFan::MODE_AUTO,
            'on', '100' => SpaceFan::MODE_FORCE_ON,
            '75' => SpaceFan::MODE_FORCE_ON,
            '50' => SpaceFan::MODE_FORCE_ON,
            default => throw new InvalidArgumentException("Unknown fan action: {$action}"),
        };
        if (in_array($action, ['50', '75', '100', 'on'], true)) {
            $forcedSpeed = match ($action) {
                '50' => SpaceFan::SPEED_NIGHT,
                '75' => SpaceFan::SPEED_MID,
                default => SpaceFan::SPEED_HIGH, // on / 100
            };
        }

        return DB::transaction(function () use ($computer, $mode, $forcedSpeed) {
            /** @var SpaceFan|null $fan */
            $fan = SpaceFan::query()
                ->where('space_id', $computer->space_id)
                ->where('club_id', $computer->club_id)
                ->lockForUpdate()
                ->first();

            if (! $fan) {
                return ['fan' => null, 'locked' => false, 'remaining_sec' => 0];
            }

            $cooldown = max(0, (int) config('fan.manual_cooldown_sec', 10));
            $remaining = $this->manualLockRemainingSec($fan, $cooldown);
            $sameForceSpeed = $mode === SpaceFan::MODE_FORCE_ON
                && $forcedSpeed !== null
                && SpaceFan::normalizeSpeed((int) $fan->default_on_power) === $forcedSpeed
                && $fan->manual_mode === SpaceFan::MODE_FORCE_ON;
            $unchanged = ($fan->manual_mode === $mode && ($mode !== SpaceFan::MODE_FORCE_ON || $sameForceSpeed));

            if ($remaining > 0 && ! $unchanged) {
                $fan->desired_power = $this->computeDesiredPower($fan);

                return ['fan' => $fan, 'locked' => true, 'remaining_sec' => $remaining];
            }

            $fan->manual_mode = $mode;
            if ($forcedSpeed !== null) {
                $fan->default_on_power = $forcedSpeed;
            } elseif ($mode === SpaceFan::MODE_AUTO) {
                $fan->default_on_power = SpaceFan::SPEED_HIGH;
            }
            $fan->last_manual_at = now();
            $fan->last_manual_by_computer_id = (int) $computer->id;
            $fan->desired_power = $this->computeDesiredPower($fan);
            $fan->save();

            return ['fan' => $fan, 'locked' => false, 'remaining_sec' => 0];
        });
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

    /**
     * Shell acknowledges physical relay state after W5100 command or /99 status read.
     *
     * @param  'command'|'status_read'  $source
     * @return array{fan: ?SpaceFan, locked: bool, remaining_sec: int}
     */
    public function acknowledgeApplied(
        int $computerId,
        int $appliedPower,
        ?string $error = null,
        string $source = 'command',
    ): array {
        $computer = Computer::query()->find($computerId);
        if (! $computer || ! $computer->space_id) {
            return ['fan' => null, 'locked' => false, 'remaining_sec' => 0];
        }

        $appliedPower = SpaceFan::normalizeSpeed($appliedPower);

        return DB::transaction(function () use ($computer, $appliedPower, $error, $source) {
            /** @var SpaceFan|null $fan */
            $fan = SpaceFan::query()
                ->where('space_id', $computer->space_id)
                ->where('club_id', $computer->club_id)
                ->lockForUpdate()
                ->first();

            if (! $fan) {
                return ['fan' => null, 'locked' => false, 'remaining_sec' => 0];
            }

            $cooldown = max(0, (int) config('fan.auto_apply_cooldown_sec', 20));
            if ($source === 'command'
                && (int) $fan->applied_power !== $appliedPower
                && $fan->last_applied_by_computer_id
                && (int) $fan->last_applied_by_computer_id !== (int) $computer->id
            ) {
                $remaining = $this->autoLockRemainingSec($fan, $cooldown);
                if ($remaining > 0) {
                    return ['fan' => $fan, 'locked' => true, 'remaining_sec' => $remaining];
                }
            }

            $fan->applied_power = SpaceFan::normalizeSpeed($appliedPower);
            $fan->desired_power = $this->computeDesiredPower($fan);
            $fan->last_error = $error;
            $fan->last_applied_at = now();
            $fan->last_applied_by_computer_id = (int) $computer->id;
            $fan->save();

            return ['fan' => $fan, 'locked' => false, 'remaining_sec' => 0];
        });
    }

    /**
     * Admin force-off for orphan fans. Optionally wake one PC via WOL desired.
     *
     * @return array{fan: ?SpaceFan, wol_computer_id: ?int}
     */
    public function adminForceOff(int $spaceFanId): array
    {
        return DB::transaction(function () use ($spaceFanId) {
            /** @var SpaceFan|null $fan */
            $fan = SpaceFan::query()->lockForUpdate()->find($spaceFanId);
            if (! $fan) {
                return ['fan' => null, 'wol_computer_id' => null];
            }

            $fan->manual_mode = SpaceFan::MODE_FORCE_OFF;
            $fan->desired_power = SpaceFan::SPEED_NIGHT;
            $fan->last_manual_at = now();
            $fan->last_manual_by_computer_id = null;
            $fan->save();

            $wolComputerId = null;
            if ($this->spaceAllComputersOffline($fan) && (int) $fan->applied_power >= SpaceFan::SPEED_MID) {
                $wolComputerId = $this->wakeOneComputerInSpace($fan);
            }

            return ['fan' => $fan, 'wol_computer_id' => $wolComputerId];
        });
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
            ->with('relayBoard')
            ->first();

        if (! $fan) {
            return [
                'available' => false,
                'reason' => 'no_fan',
                'space_id' => (int) $computer->space_id,
                'club_id' => (int) $computer->club_id,
            ];
        }

        return $this->statePayload($fan, (int) $computer->id);
    }

    public function statePayload(SpaceFan $fan, ?int $forComputerId = null): array
    {
        $fan->loadMissing('relayBoard');
        $board = $fan->relayBoard;
        $sessions = $this->spaceActiveSessionCount($fan);
        $thermal = $this->spaceHasThermalHot($fan);
        $desired = $this->computeDesiredPower($fan);
        $manualCooldown = max(0, (int) config('fan.manual_cooldown_sec', 10));
        $autoCooldown = max(0, (int) config('fan.auto_apply_cooldown_sec', 20));
        $manualRemaining = $this->manualLockRemainingSec($fan, $manualCooldown);
        $autoRemaining = $this->autoLockRemainingSec($fan, $autoCooldown);

        $relay = null;
        if ($board && $board->is_active) {
            $relay = [
                'host' => (string) $board->host,
                'port' => (int) ($board->port ?: config('fan.w5100_default_port', 30000)),
                'channel' => (int) $fan->channel,
                'channel2' => (int) $fan->channel2,
                'driver' => (string) $board->driver,
            ];
        }

        $speed = SpaceFan::normalizeSpeed($desired);

        return [
            'available' => $relay !== null,
            'fan_id' => (int) $fan->id,
            'club_id' => (int) $fan->club_id,
            'space_id' => (int) $fan->space_id,
            'relay' => $relay,
            'manual_mode' => $fan->manual_mode,
            'desired_power' => $speed,
            'desired_speed' => $speed,
            'applied_power' => SpaceFan::normalizeSpeed((int) $fan->applied_power),
            'applied_speed' => SpaceFan::normalizeSpeed((int) $fan->applied_power),
            'default_on_power' => SpaceFan::normalizeSpeed((int) ($fan->default_on_power ?: SpaceFan::SPEED_HIGH)),
            'thermal_on_c' => (int) ($fan->thermal_on_c ?: config('fan.thermal_on_c', 75)),
            'thermal_off_c' => (int) ($fan->thermal_off_c ?: config('fan.thermal_off_c', 65)),
            'is_on' => $fan->isOn(),
            'speed_labels' => [
                1 => 'night_120v',
                2 => 'mid_170v',
                3 => 'high_220v',
            ],
            'last_error' => $fan->last_error,
            'facts' => [
                'session' => $sessions > 0,
                'thermal' => $thermal,
                'sessions_in_space' => $sessions,
                'is_last_session' => $sessions <= 1 && $sessions > 0,
            ],
            'manual_lock' => [
                'locked' => $manualRemaining > 0,
                'remaining_sec' => $manualRemaining,
            ],
            'auto_lock' => [
                'locked' => $autoRemaining > 0,
                'remaining_sec' => $autoRemaining,
            ],
            'reasons' => [
                'session' => $sessions > 0,
                'thermal' => $thermal,
                'manual_mode' => $fan->manual_mode,
            ],
            'for_computer_id' => $forComputerId,
        ];
    }

    /**
     * Orphan fan flags for admin dashboard (fan ON while all PCs in space offline).
     *
     * @return list<array{fan_id:int,space_id:int,club_id:int,applied_power:int,manual_mode:string,fan_orphan_on:bool}>
     */
    public function orphanSnapshot(?int $clubId = null): array
    {
        $query = SpaceFan::query()->with('relayBoard');
        if ($clubId) {
            $query->where('club_id', $clubId);
        }

        $out = [];
        foreach ($query->get() as $fan) {
            $orphan = (int) $fan->applied_power >= SpaceFan::SPEED_MID && $this->spaceAllComputersOffline($fan);
            $out[] = [
                'fan_id' => (int) $fan->id,
                'space_id' => (int) $fan->space_id,
                'club_id' => (int) $fan->club_id,
                'applied_power' => SpaceFan::normalizeSpeed((int) $fan->applied_power),
                'applied_speed' => SpaceFan::normalizeSpeed((int) $fan->applied_power),
                'manual_mode' => (string) $fan->manual_mode,
                'fan_orphan_on' => $orphan,
                'is_on' => $fan->isOn(),
            ];
        }

        return $out;
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

    public function computeDesiredPower(SpaceFan $fan): int
    {
        if ($fan->manual_mode === SpaceFan::MODE_FORCE_OFF) {
            return SpaceFan::SPEED_NIGHT;
        }

        if ($fan->manual_mode === SpaceFan::MODE_FORCE_ON) {
            return SpaceFan::normalizeSpeed((int) ($fan->default_on_power ?: SpaceFan::SPEED_HIGH));
        }

        // Session → max 220V; thermal (cool-down hint) → mid 170V; else night 120V.
        if ($this->spaceHasActiveSession($fan)) {
            return SpaceFan::SPEED_HIGH;
        }

        if ($this->spaceHasThermalHot($fan)) {
            return SpaceFan::SPEED_MID;
        }

        return SpaceFan::SPEED_NIGHT;
    }

    private function manualLockRemainingSec(SpaceFan $fan, int $cooldown): int
    {
        if ($cooldown <= 0 || ! $fan->last_manual_at) {
            return 0;
        }

        $elapsed = $fan->last_manual_at->diffInSeconds(now());

        return max(0, $cooldown - (int) $elapsed);
    }

    private function autoLockRemainingSec(SpaceFan $fan, int $cooldown): int
    {
        if ($cooldown <= 0 || ! $fan->last_applied_at) {
            return 0;
        }

        $elapsed = $fan->last_applied_at->diffInSeconds(now());

        return max(0, $cooldown - (int) $elapsed);
    }

    private function spaceHasActiveSession(SpaceFan $fan): bool
    {
        return $this->spaceActiveSessionCount($fan) > 0;
    }

    private function spaceActiveSessionCount(SpaceFan $fan): int
    {
        $computerIds = Computer::query()
            ->where('space_id', $fan->space_id)
            ->where('club_id', $fan->club_id)
            ->pluck('id');

        if ($computerIds->isEmpty()) {
            return 0;
        }

        return (int) Booking::query()
            ->where('status', 'active')
            ->whereIn('computer_id', $computerIds)
            ->count();
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

    private function spaceAllComputersOffline(SpaceFan $fan): bool
    {
        $computers = Computer::query()
            ->where('space_id', $fan->space_id)
            ->where('club_id', $fan->club_id)
            ->get(['id', 'power_state', 'last_seen_at']);

        if ($computers->isEmpty()) {
            return true;
        }

        $staleSec = max(30, (int) config('club.power.heartbeat_stale_seconds', 180));
        $cutoff = now()->subSeconds($staleSec);

        foreach ($computers as $pc) {
            $state = (string) ($pc->power_state ?? 'off');
            if (in_array($state, ['on', 'booting'], true)) {
                return false;
            }
            if ($pc->last_seen_at && $pc->last_seen_at->gte($cutoff)) {
                return false;
            }
        }

        return true;
    }

    private function wakeOneComputerInSpace(SpaceFan $fan): ?int
    {
        $pc = Computer::query()
            ->where('space_id', $fan->space_id)
            ->where('club_id', $fan->club_id)
            ->whereNotNull('mac_address')
            ->where('mac_address', '!=', '')
            ->orderBy('id')
            ->first();

        if (! $pc) {
            $pc = Computer::query()
                ->where('space_id', $fan->space_id)
                ->where('club_id', $fan->club_id)
                ->orderBy('id')
                ->first();
        }

        if (! $pc) {
            return null;
        }

        DB::table('computers')->where('id', $pc->id)->update([
            'power_desired' => ComputerPowerService::DESIRED_ON,
            'updated_at' => now(),
        ]);

        return (int) $pc->id;
    }

    /**
     * Shell discovery: boards + cascade pairs (1+2, 3+4, …) for this PC's club/space.
     *
     * @return array{available:bool,reason?:string,club_id?:int,space_id?:int,space_name?:string,current?:?array,boards:list<array>}
     */
    public function discoverForComputer(int $computerId): array
    {
        $computer = Computer::query()->with('space:id,name')->find($computerId);
        if (! $computer || ! $computer->club_id) {
            return ['available' => false, 'reason' => 'no_computer', 'boards' => []];
        }
        if (! $computer->space_id) {
            return [
                'available' => false,
                'reason' => 'no_space',
                'club_id' => (int) $computer->club_id,
                'boards' => [],
            ];
        }

        $clubId = (int) $computer->club_id;
        $spaceId = (int) $computer->space_id;

        $currentFan = SpaceFan::query()
            ->where('space_id', $spaceId)
            ->where('club_id', $clubId)
            ->with('relayBoard:id,name,host,port')
            ->first();

        $fansOnClub = SpaceFan::query()
            ->where('club_id', $clubId)
            ->with('space:id,name')
            ->get();

        $usage = []; // boardId => [channel => ['space_id'=>, 'space_name'=>, 'fan_id'=>]]
        foreach ($fansOnClub as $fan) {
            $bid = (int) $fan->relay_board_id;
            foreach ([(int) $fan->channel, (int) $fan->channel2] as $ch) {
                $usage[$bid][$ch] = [
                    'space_id' => (int) $fan->space_id,
                    'space_name' => (string) ($fan->space?->name ?: ('#'.$fan->space_id)),
                    'fan_id' => (int) $fan->id,
                ];
            }
        }

        $boards = RelayBoard::query()
            ->where('club_id', $clubId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $boardPayload = [];
        foreach ($boards as $board) {
            $pairs = [];
            for ($k1 = 1; $k1 <= 15; $k1 += 2) {
                $k2 = $k1 + 1;
                $u1 = $usage[(int) $board->id][$k1] ?? null;
                $u2 = $usage[(int) $board->id][$k2] ?? null;
                $status = 'free';
                $ownerSpaceId = null;
                $ownerName = null;
                if ($u1 || $u2) {
                    $owner = $u1 ?: $u2;
                    $ownerSpaceId = (int) $owner['space_id'];
                    $ownerName = (string) $owner['space_name'];
                    $status = $ownerSpaceId === $spaceId ? 'mine' : 'taken';
                }
                $pairs[] = [
                    'channel' => $k1,
                    'channel2' => $k2,
                    'label' => "K{$k1}+K{$k2}",
                    'status' => $status,
                    'space_id' => $ownerSpaceId,
                    'space_name' => $ownerName,
                ];
            }
            $boardPayload[] = [
                'id' => (int) $board->id,
                'name' => (string) $board->name,
                'host' => (string) $board->host,
                'port' => (int) ($board->port ?: config('fan.w5100_default_port', 30000)),
                'driver' => (string) $board->driver,
                'pairs' => $pairs,
            ];
        }

        $current = null;
        if ($currentFan) {
            $current = [
                'fan_id' => (int) $currentFan->id,
                'relay_board_id' => (int) $currentFan->relay_board_id,
                'channel' => (int) $currentFan->channel,
                'channel2' => (int) $currentFan->channel2,
                'host' => (string) ($currentFan->relayBoard?->host ?? ''),
                'port' => (int) ($currentFan->relayBoard?->port ?? 30000),
            ];
        }

        return [
            'available' => true,
            'club_id' => $clubId,
            'space_id' => $spaceId,
            'space_name' => (string) ($computer->space?->name ?: ('#'.$spaceId)),
            'current' => $current,
            'boards' => $boardPayload,
        ];
    }

    /**
     * Bind cascade pair to this computer's space (create or update SpaceFan).
     *
     * @return array{ok:bool,message:string,fan:?array}
     */
    public function bindForComputer(int $computerId, int $boardId, int $channel, int $channel2): array
    {
        $computer = Computer::query()->find($computerId);
        if (! $computer || ! $computer->club_id || ! $computer->space_id) {
            return ['ok' => false, 'message' => 'ПК без клуба/комнаты', 'fan' => null];
        }
        if ($channel < 1 || $channel > 16 || $channel2 < 1 || $channel2 > 16 || $channel === $channel2) {
            return ['ok' => false, 'message' => 'Некорректные каналы K1/K2', 'fan' => null];
        }

        $board = RelayBoard::query()
            ->where('id', $boardId)
            ->where('club_id', $computer->club_id)
            ->where('is_active', true)
            ->first();
        if (! $board) {
            return ['ok' => false, 'message' => 'Плата не найдена в клубе', 'fan' => null];
        }

        return DB::transaction(function () use ($computer, $board, $channel, $channel2) {
            $clubId = (int) $computer->club_id;
            $spaceId = (int) $computer->space_id;

            $mine = SpaceFan::query()
                ->where('space_id', $spaceId)
                ->where('club_id', $clubId)
                ->lockForUpdate()
                ->first();

            $conflicts = SpaceFan::query()
                ->where('relay_board_id', $board->id)
                ->where('club_id', $clubId)
                ->when($mine, fn ($q) => $q->where('id', '!=', $mine->id))
                ->lockForUpdate()
                ->get(['id', 'space_id', 'channel', 'channel2']);

            foreach ($conflicts as $row) {
                $used = [(int) $row->channel, (int) $row->channel2];
                if (in_array($channel, $used, true) || in_array($channel2, $used, true)) {
                    return [
                        'ok' => false,
                        'message' => 'Каналы заняты другой комнатой (space #'.$row->space_id.')',
                        'fan' => null,
                    ];
                }
            }

            $payload = [
                'club_id' => $clubId,
                'space_id' => $spaceId,
                'relay_board_id' => (int) $board->id,
                'channel' => $channel,
                'channel2' => $channel2,
                'manual_mode' => SpaceFan::MODE_AUTO,
                'desired_power' => SpaceFan::SPEED_NIGHT,
                'default_on_power' => SpaceFan::SPEED_HIGH,
                'thermal_on_c' => (int) config('fan.thermal_on_c', 75),
                'thermal_off_c' => (int) config('fan.thermal_off_c', 65),
            ];

            if ($mine) {
                $mine->fill($payload);
                $mine->save();
                $fan = $mine;
                $msg = 'Привязка обновлена: K'.$channel.'+K'.$channel2;
            } else {
                $payload['applied_power'] = SpaceFan::SPEED_NIGHT;
                $fan = SpaceFan::create($payload);
                $msg = 'Вентилятор привязан: K'.$channel.'+K'.$channel2;
            }

            return [
                'ok' => true,
                'message' => $msg,
                'fan' => $this->statePayload($fan->fresh(['relayBoard']), (int) $computer->id),
            ];
        });
    }
}
