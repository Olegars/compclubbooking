<?php

namespace App\Services;

use App\Models\Computer;
use App\Support\SqlTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Тихий re-sync повреждённых файлов D: с эталона бездиска.
 * Не Super Client: шелл копирует через robocopy/FastCopy в фоне.
 */
class ImageResyncService
{
    public const ACTION_RESYNC = 'resync_files';

    public function ttlMinutes(): int
    {
        return 20;
    }

    /**
     * @return array{command_id: int, action: string}|null
     */
    public function pendingFor(Computer $computer): ?array
    {
        $action = (string) ($computer->resync_command ?? '');
        $id = (int) ($computer->resync_command_id ?? 0);
        if ($action === '' || $id <= 0) {
            return null;
        }

        $at = $computer->resync_command_at;
        if ($at && $at->lessThan(CarbonImmutable::now()->subMinutes($this->ttlMinutes()))) {
            DB::table('computers')->where('id', $computer->id)->update([
                'resync_command' => null,
                'resync_command_id' => null,
                'resync_command_at' => null,
                'resync_result' => 'timeout',
                'resync_message' => 'Шелл не подтвердил re-sync за '.$this->ttlMinutes().' мин',
                'updated_at' => SqlTime::now(),
            ]);
            $computer->resync_command = null;
            $computer->resync_command_id = null;

            return null;
        }

        return [
            'command_id' => $id,
            'action' => $action === '' ? self::ACTION_RESYNC : $action,
        ];
    }

    public function ack(Computer $computer, ?int $ackId, ?string $result, ?string $message): void
    {
        if (! $ackId || $ackId <= 0) {
            return;
        }

        $patch = [
            'resync_result' => $result ? mb_substr($result, 0, 32) : 'accepted',
            'resync_message' => $message ? mb_substr($message, 0, 240) : null,
            'updated_at' => SqlTime::now(),
        ];

        if (in_array($result, ['ok', 'done', 'accepted', 'running'], true)) {
            $patch['integrity_status'] = $result === 'ok' || $result === 'done' ? 'ok' : 'resyncing';
        }

        if ((int) $computer->resync_command_id === $ackId && $result !== 'running') {
            $patch['resync_command'] = null;
            $patch['resync_command_id'] = null;
            $patch['resync_command_at'] = null;
        }

        DB::table('computers')->where('id', $computer->id)->update($patch);
        Log::info('Image resync acked', [
            'computer_id' => $computer->id,
            'ack_id' => $ackId,
            'result' => $result,
        ]);
    }

    /**
     * @return array{command_id: int, action: string}
     */
    public function enqueue(Computer $computer, ?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();

        $power = app(ComputerPowerService::class);
        $stale = $power->staleSeconds();
        $online = $computer->last_seen_at
            && $computer->last_seen_at->greaterThanOrEqualTo($now->subSeconds($stale));
        if (! $online) {
            throw ValidationException::withMessages([
                'computer_id' => 'ПК офлайн — тихий re-sync только на живом шелле.',
            ]);
        }

        $id = (int) ($computer->resync_command_id ?? 0);
        $id = $id > 0 ? $id + 1 : (int) floor(microtime(true) * 1000);

        DB::table('computers')->where('id', $computer->id)->update([
            'resync_command' => self::ACTION_RESYNC,
            'resync_command_id' => $id,
            'resync_command_at' => $now,
            'resync_result' => 'queued',
            'resync_message' => null,
            'integrity_status' => 'resyncing',
            'updated_at' => SqlTime::now(),
        ]);

        Log::warning('Image resync queued', [
            'computer_id' => $computer->id,
            'computer_name' => $computer->name,
            'command_id' => $id,
        ]);

        return [
            'command_id' => $id,
            'action' => self::ACTION_RESYNC,
        ];
    }
}
