<?php

namespace App\Services;

use App\Models\Computer;
use App\Support\SqlTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LAN P2P-кэшер патчей: seed (Super Client / живой сид с портом)
 * раздаёт свежие Steam/Epic buildId, peer-станции тянут по VLAN.
 */
class LanPatchService
{
    public function ttlMinutes(): int
    {
        return 45;
    }

    /**
     * @param  array{
     *     lan_ip?: string|null,
     *     patch_seed_port?: int|string|null,
     *     games_inventory?: array<int, mixed>|null,
     *     patch_pull_ack_id?: int|string|null,
     *     patch_pull_result?: string|null,
     *     patch_pull_message?: string|null
     * }  $extras
     * @return array{
     *     patch_seed: array{enabled: bool, port: int}|null,
     *     patch_pull: array{command_id: int, apps: list<array<string, mixed>>}|null
     * }
     */
    public function processHeartbeat(Computer $computer, array $extras = []): array
    {
        $this->applyExtras($computer, $extras);
        $computer->refresh();

        $ackId = isset($extras['patch_pull_ack_id']) ? (int) $extras['patch_pull_ack_id'] : 0;
        if ($ackId > 0) {
            $this->ack($computer, $ackId,
                isset($extras['patch_pull_result']) ? (string) $extras['patch_pull_result'] : null,
                isset($extras['patch_pull_message']) ? (string) $extras['patch_pull_message'] : null);
            $computer->refresh();
        }

        $seed = $this->seedPolicyFor($computer);
        $pull = $this->pendingPullFor($computer);

        if ($pull === null && ! ($computer->super_client ?? false)) {
            $this->maybeEnqueuePull($computer);
            $computer->refresh();
            $pull = $this->pendingPullFor($computer);
        }

        return [
            'patch_seed' => $seed,
            'patch_pull' => $pull,
        ];
    }

    /**
     * @return array{enabled: bool, port: int}|null
     */
    public function seedPolicyFor(Computer $computer): ?array
    {
        $port = (int) ($computer->patch_seed_port ?: 8745);
        if ($port <= 0) {
            $port = 8745;
        }

        if ($computer->super_client) {
            return ['enabled' => true, 'port' => $port];
        }

        // Уже слушает — держим сид, пока нет гостя (сессия на уровне power_action).
        if ((int) ($computer->patch_seed_port ?? 0) > 0 && ! empty($computer->lan_ip)) {
            return ['enabled' => true, 'port' => (int) $computer->patch_seed_port];
        }

        return null;
    }

    /**
     * @return array{command_id: int, apps: list<array<string, mixed>>}|null
     */
    public function pendingPullFor(Computer $computer): ?array
    {
        $id = (int) ($computer->patch_pull_command_id ?? 0);
        $payload = $computer->patch_pull_payload;
        if ($id <= 0 || ! is_array($payload) || empty($payload['apps'])) {
            return null;
        }

        $at = $computer->patch_pull_command_at;
        if ($at && $at->lessThan(CarbonImmutable::now()->subMinutes($this->ttlMinutes()))) {
            DB::table('computers')->where('id', $computer->id)->update([
                'patch_pull_command_id' => null,
                'patch_pull_command_at' => null,
                'patch_pull_payload' => null,
                'patch_pull_result' => 'timeout',
                'patch_pull_message' => 'LAN pull не подтверждён за '.$this->ttlMinutes().' мин',
                'updated_at' => SqlTime::now(),
            ]);

            return null;
        }

        return [
            'command_id' => $id,
            'apps' => array_values($payload['apps']),
        ];
    }

    public function ack(Computer $computer, ?int $ackId, ?string $result, ?string $message): void
    {
        if (! $ackId || $ackId <= 0) {
            return;
        }

        $patch = [
            'patch_pull_result' => $result ? mb_substr($result, 0, 32) : 'accepted',
            'patch_pull_message' => $message ? mb_substr($message, 0, 240) : null,
            'updated_at' => SqlTime::now(),
        ];

        if ((int) $computer->patch_pull_command_id === $ackId && ! in_array($result, ['running'], true)) {
            $patch['patch_pull_command_id'] = null;
            $patch['patch_pull_command_at'] = null;
            $patch['patch_pull_payload'] = null;
        }

        DB::table('computers')->where('id', $computer->id)->update($patch);
        Log::info('LAN patch pull acked', [
            'computer_id' => $computer->id,
            'ack_id' => $ackId,
            'result' => $result,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extras
     */
    private function applyExtras(Computer $computer, array $extras): void
    {
        $patch = [];
        if (! empty($extras['lan_ip']) && filter_var((string) $extras['lan_ip'], FILTER_VALIDATE_IP)) {
            $patch['lan_ip'] = (string) $extras['lan_ip'];
        }
        if (array_key_exists('patch_seed_port', $extras) && $extras['patch_seed_port'] !== null && $extras['patch_seed_port'] !== '') {
            $port = (int) $extras['patch_seed_port'];
            $patch['patch_seed_port'] = $port > 0 ? min(65535, $port) : null;
        }
        if ($patch !== []) {
            $patch['updated_at'] = SqlTime::now();
            DB::table('computers')->where('id', $computer->id)->update($patch);
        }
    }

    private function maybeEnqueuePull(Computer $computer): void
    {
        if ((int) ($computer->patch_pull_command_id ?? 0) > 0) {
            return;
        }
        if (empty($computer->games_inventory) || ! is_array($computer->games_inventory)) {
            return;
        }

        $seed = $this->findSeed($computer);
        if (! $seed || empty($seed->lan_ip) || (int) $seed->patch_seed_port <= 0) {
            return;
        }
        if (empty($seed->games_inventory) || ! is_array($seed->games_inventory)) {
            return;
        }

        $seedIndex = [];
        foreach ($seed->games_inventory as $row) {
            if (! is_array($row)) {
                continue;
            }
            $p = strtolower((string) ($row['p'] ?? ''));
            $id = (string) ($row['id'] ?? '');
            $b = (string) ($row['b'] ?? '');
            if ($p === '' || $id === '' || $b === '') {
                continue;
            }
            $seedIndex[$p.':'.$id] = $b;
        }

        $apps = [];
        foreach ($computer->games_inventory as $row) {
            if (! is_array($row)) {
                continue;
            }
            $p = strtolower((string) ($row['p'] ?? ''));
            $id = (string) ($row['id'] ?? '');
            $localBuild = (string) ($row['b'] ?? '');
            if ($p === '' || $id === '') {
                continue;
            }
            $key = $p.':'.$id;
            $seedBuild = $seedIndex[$key] ?? null;
            if ($seedBuild === null || $seedBuild === '' || $seedBuild === $localBuild) {
                continue;
            }
            if (is_numeric($seedBuild) && is_numeric($localBuild) && (int) $seedBuild <= (int) $localBuild) {
                continue;
            }

            $apps[] = [
                'p' => $p,
                'id' => $id,
                'b' => $seedBuild,
                'peer_ip' => (string) $seed->lan_ip,
                'peer_port' => (int) $seed->patch_seed_port,
                'n' => (string) ($row['n'] ?? ''),
            ];
            if (count($apps) >= 8) {
                break;
            }
        }

        if ($apps === []) {
            return;
        }

        $commandId = (int) floor(microtime(true) * 1000);
        DB::table('computers')->where('id', $computer->id)->update([
            'patch_pull_command_id' => $commandId,
            'patch_pull_command_at' => SqlTime::now(),
            'patch_pull_payload' => json_encode(['apps' => $apps], JSON_UNESCAPED_UNICODE),
            'patch_pull_result' => 'queued',
            'patch_pull_message' => null,
            'updated_at' => SqlTime::now(),
        ]);

        Log::info('[PATCH-CACHE] pull queued', [
            'computer_id' => $computer->id,
            'seed_id' => $seed->id,
            'apps' => count($apps),
            'command_id' => $commandId,
        ]);
    }

    private function findSeed(Computer $computer): ?Computer
    {
        $q = Computer::query()
            ->where('id', '!=', $computer->id)
            ->whereNotNull('lan_ip')
            ->where('patch_seed_port', '>', 0)
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', now()->subSeconds(
                max(30, (int) config('club.power.heartbeat_stale_seconds', 180))
            ));

        if ($computer->club_id) {
            $q->where('club_id', $computer->club_id);
        }

        $seed = (clone $q)->where('super_client', true)->orderBy('id')->first();
        if ($seed) {
            return $seed;
        }

        return $q->orderByDesc('super_client')->orderBy('id')->first();
    }
}
