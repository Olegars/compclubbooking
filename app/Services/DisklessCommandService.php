<?php

namespace App\Services;

use App\Models\Computer;
use App\Support\SqlTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Очередь Super Client: админка ставит команду, шелл забирает её в ответе heartbeat.
 * Пароль CCBoot на клиенте (config.ini), не в облаке.
 */
class DisklessCommandService
{
    public const ACTION_ENABLE = 'enable_sc';

    public const ACTION_DISABLE_SAVE = 'disable_sc_save';

    public const ACTION_DISABLE_DISCARD = 'disable_sc_discard';

    public const ACTIONS = [
        self::ACTION_ENABLE,
        self::ACTION_DISABLE_SAVE,
        self::ACTION_DISABLE_DISCARD,
    ];

    public const MODES = ['image', 'disk', 'both'];

    public function ttlMinutes(): int
    {
        return 12;
    }

    /**
     * @return array{command_id: int, action: string, disk_mode: string}|null
     */
    public function pendingFor(Computer $computer): ?array
    {
        $action = (string) ($computer->diskless_command ?? '');
        $id = (int) ($computer->diskless_command_id ?? 0);
        if ($action === '' || $id <= 0) {
            return null;
        }

        $at = $computer->diskless_command_at;
        if ($at && $at->lessThan(CarbonImmutable::now()->subMinutes($this->ttlMinutes()))) {
            DB::table('computers')->where('id', $computer->id)->update([
                'diskless_command' => null,
                'diskless_disk_mode' => null,
                'diskless_command_id' => null,
                'diskless_command_at' => null,
                'diskless_result' => 'timeout',
                'diskless_message' => 'Шелл не подтвердил команду за '.$this->ttlMinutes().' мин',
                'updated_at' => SqlTime::now(),
            ]);
            $computer->diskless_command = null;
            $computer->diskless_command_id = null;

            return null;
        }

        $mode = (string) ($computer->diskless_disk_mode ?: 'image');
        if (! in_array($mode, self::MODES, true)) {
            $mode = 'image';
        }

        return [
            'command_id' => $id,
            'action' => $action,
            'disk_mode' => $mode,
        ];
    }

    public function ack(Computer $computer, ?int $ackId, ?string $result, ?string $message): void
    {
        if (! $ackId || $ackId <= 0) {
            return;
        }

        $patch = [
            'diskless_result' => $result ? mb_substr($result, 0, 32) : 'accepted',
            'diskless_message' => $message ? mb_substr($message, 0, 240) : null,
            'updated_at' => SqlTime::now(),
        ];

        if ((int) $computer->diskless_command_id === $ackId) {
            $patch['diskless_command'] = null;
            $patch['diskless_disk_mode'] = null;
            $patch['diskless_command_id'] = null;
            $patch['diskless_command_at'] = null;
        }

        DB::table('computers')->where('id', $computer->id)->update($patch);
        Log::info('Diskless command acked', [
            'computer_id' => $computer->id,
            'ack_id' => $ackId,
            'result' => $result,
        ]);
    }

    /**
     * @return array{command_id: int, action: string, disk_mode: string}
     */
    public function enqueue(
        Computer $computer,
        string $action,
        string $diskMode = 'image',
        bool $confirmGameDisk = false,
        ?CarbonImmutable $now = null,
    ): array {
        $now = $now ?? CarbonImmutable::now();
        $action = trim($action);
        $diskMode = strtolower(trim($diskMode ?: 'image'));

        if (! in_array($action, self::ACTIONS, true)) {
            throw ValidationException::withMessages([
                'action' => 'Неизвестная команда Super Client.',
            ]);
        }
        if (! in_array($diskMode, self::MODES, true)) {
            $diskMode = 'image';
        }
        if ($action === self::ACTION_ENABLE && in_array($diskMode, ['disk', 'both'], true) && ! $confirmGameDisk) {
            throw ValidationException::withMessages([
                'disk_mode' => 'Super Client на game disk лочит том. Подтвердите отдельно.',
            ]);
        }

        $power = app(ComputerPowerService::class);
        $stale = $power->staleSeconds();
        $online = $computer->last_seen_at
            && $computer->last_seen_at->greaterThanOrEqualTo($now->subSeconds($stale));
        if (! $online) {
            throw ValidationException::withMessages([
                'computer_id' => 'ПК офлайн — Super Client только на живом шелле.',
            ]);
        }

        $busy = DB::table('bookings')
            ->where('computer_id', $computer->id)
            ->where('status', 'active')
            ->exists();
        if ($busy) {
            throw ValidationException::withMessages([
                'computer_id' => 'На месте активная сессия. Сначала logout / освободить ПК.',
            ]);
        }

        if ($action === self::ACTION_ENABLE) {
            $other = Computer::query()
                ->where('id', '!=', $computer->id)
                ->when($computer->club_id, fn ($q) => $q->where('club_id', $computer->club_id))
                ->where(function ($q) {
                    $q->where('super_client', true)
                        ->orWhere('diskless_command', self::ACTION_ENABLE);
                })
                ->orderBy('id')
                ->first(['id', 'name']);
            if ($other) {
                throw ValidationException::withMessages([
                    'computer_id' => 'Super Client уже на '.$other->name.'. Сначала сохраните и выключите там.',
                ]);
            }
        }

        $id = (int) ($computer->diskless_command_id ?? 0);
        $id = $id > 0 ? $id + 1 : (int) floor(microtime(true) * 1000);

        DB::table('computers')->where('id', $computer->id)->update([
            'diskless_command' => $action,
            'diskless_disk_mode' => $diskMode,
            'diskless_command_id' => $id,
            'diskless_command_at' => $now,
            'diskless_result' => 'queued',
            'diskless_message' => null,
            'maintenance' => true,
            'status' => 'maintenance',
            'updated_at' => SqlTime::now(),
        ]);

        Log::warning('Diskless command queued', [
            'computer_id' => $computer->id,
            'computer_name' => $computer->name,
            'action' => $action,
            'disk_mode' => $diskMode,
            'command_id' => $id,
        ]);

        return [
            'command_id' => $id,
            'action' => $action,
            'disk_mode' => $diskMode,
        ];
    }
}
