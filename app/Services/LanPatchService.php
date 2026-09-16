<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Computer;
use App\Support\SqlTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LAN P2P-кэшер патчей: seed (Super Client / временный сид на D:)
 * раздаёт свежие Steam/Epic buildId, peer-станции тянут по VLAN.
 */
class LanPatchService
{
    public function ttlMinutes(): int
    {
        return 45;
    }

    public function nightStartHour(): int
    {
        return max(0, min(23, (int) config('club.patch_cache.night_start', 1)));
    }

    public function nightEndHour(): int
    {
        return max(0, min(23, (int) config('club.patch_cache.night_end', 6)));
    }

    public function ingestEnabled(): bool
    {
        return filter_var(config('club.patch_cache.ingest', true), FILTER_VALIDATE_BOOLEAN);
    }

    public function hysteresis(): float
    {
        return max(0.0, (float) config('club.patch_cache.fallback_hysteresis', 80));
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
     *     patch_seed: array{enabled: bool, port: int, role: string},
     *     patch_pull: array{command_id: int, apps: list<array<string, mixed>>}|null,
     *     patch_ingest: array{enabled: bool}|null
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

        if (! $this->featureOn($computer)) {
            $port = (int) ($computer->patch_seed_port ?: 8745);

            return [
                'patch_seed' => ['enabled' => false, 'port' => $port > 0 ? $port : 8745, 'role' => 'none'],
                'patch_pull' => null,
                'patch_ingest' => ['enabled' => false],
            ];
        }

        $this->electFallbackSeed($computer);
        $computer->refresh();

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
            'patch_ingest' => $this->ingestPolicyFor($computer),
        ];
    }

    /**
     * @return array{enabled: bool, port: int, role: string}
     */
    public function seedPolicyFor(Computer $computer): array
    {
        $port = (int) ($computer->patch_seed_port ?: 8745);
        if ($port <= 0) {
            $port = 8745;
        }

        if ($computer->super_client) {
            return ['enabled' => true, 'port' => $port, 'role' => 'super'];
        }

        if ($this->hasBusySession((int) $computer->id)) {
            return ['enabled' => false, 'port' => $port, 'role' => 'none'];
        }

        if ((string) ($computer->patch_seed_role ?? '') === 'fallback'
            && ! $this->clubHasOnlineSuperClient($computer->club_id)) {
            return ['enabled' => true, 'port' => $port, 'role' => 'fallback'];
        }

        return ['enabled' => false, 'port' => $port, 'role' => 'none'];
    }

    /**
     * @return array{enabled: bool}|null
     */
    public function ingestPolicyFor(Computer $computer): ?array
    {
        if (! $this->ingestEnabled()) {
            return ['enabled' => false];
        }
        if (! $this->inNightWindow()) {
            return ['enabled' => false];
        }
        if ($this->hasBusySession((int) $computer->id)) {
            return ['enabled' => false];
        }

        $policy = $this->seedPolicyFor($computer);
        if (! ($policy['enabled'] ?? false)) {
            return ['enabled' => false];
        }

        return ['enabled' => true];
    }

    public function inNightWindow(?CarbonImmutable $now = null): bool
    {
        $now = $now ?? CarbonImmutable::now();
        $hour = (int) $now->timezone(config('app.timezone'))->hour;
        $start = $this->nightStartHour();
        $end = $this->nightEndHour();
        if ($start === $end) {
            return true;
        }
        if ($start < $end) {
            return $hour >= $start && $hour < $end;
        }

        return $hour >= $start || $hour < $end;
    }

    public function shouldKeepPower(Computer $computer, ?CarbonImmutable $now = null): bool
    {
        if (! $this->featureOn($computer)) {
            return false;
        }
        if (! $this->inNightWindow($now)) {
            return false;
        }
        if ($computer->super_client) {
            return true;
        }
        if ((string) ($computer->patch_seed_role ?? '') !== 'fallback') {
            return false;
        }

        return ! $this->clubHasOnlineSuperClient($computer->club_id);
    }

    /**
     * WOL/desired=on ночью для выбранного fallback-сида, если Super Client выключен.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function computersNeedingNightPower(array $ids, ?CarbonImmutable $now = null): array
    {
        if ($ids === [] || ! $this->inNightWindow($now)) {
            return [];
        }

        $rows = DB::table('computers')
            ->whereIn('id', $ids)
            ->get(['id', 'club_id', 'super_client', 'patch_seed_role']);

        $keep = [];
        $clubOnlineSc = [];
        foreach ($rows as $row) {
            $clubId = $row->club_id !== null ? (int) $row->club_id : 0;
            if (! app(ClubFeatureService::class)->enabled($clubId > 0 ? $clubId : null, 'patch_cache')) {
                continue;
            }
            if (! array_key_exists($clubId, $clubOnlineSc)) {
                $clubOnlineSc[$clubId] = $this->clubHasOnlineSuperClient($clubId > 0 ? $clubId : null);
            }
            if (filter_var($row->super_client ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $keep[(int) $row->id] = true;

                continue;
            }
            if ((string) ($row->patch_seed_role ?? '') === 'fallback' && ! $clubOnlineSc[$clubId]) {
                $keep[(int) $row->id] = true;
            }
        }

        return array_values(array_keys($keep));
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

        $fallback = (clone $q)->where('patch_seed_role', 'fallback')->orderBy('id')->first();
        if ($fallback) {
            return $fallback;
        }

        return $q->orderByDesc('super_client')->orderBy('id')->first();
    }

    private function electFallbackSeed(Computer $computer): void
    {
        $clubId = $computer->club_id ? (int) $computer->club_id : null;
        $q = Computer::query()->where('kind', Computer::KIND_PC);
        if ($clubId) {
            $q->where('club_id', $clubId);
        }

        $pcs = $q->get();
        $seenCutoff = CarbonImmutable::now()->subDays(3);

        $ranked = [];
        foreach ($pcs as $pc) {
            if (! $pc->cache_ok) {
                continue;
            }
            if (strtolower((string) ($pc->ssd_health ?? '')) === 'unhealthy') {
                continue;
            }
            $seen = $pc->last_seen_at;
            if (! $seen || $seen->lessThan($seenCutoff)) {
                continue;
            }
            $ranked[] = [
                'id' => (int) $pc->id,
                'score' => $this->seedScore($pc),
                'role' => (string) ($pc->patch_seed_role ?? ''),
                'super' => (bool) ($pc->super_client ?? false),
            ];
        }

        if ($ranked === []) {
            $this->persistFallbackWinner($clubId, null);

            return;
        }

        $standby = array_values(array_filter($ranked, fn (array $row) => ! $row['super']));
        $pool = $standby !== [] ? $standby : $ranked;

        usort($pool, function (array $a, array $b) {
            if ($a['score'] === $b['score']) {
                return $a['id'] <=> $b['id'];
            }

            return $b['score'] <=> $a['score'];
        });

        $best = $pool[0];
        $current = null;
        foreach ($pool as $row) {
            if ($row['role'] === 'fallback') {
                $current = $row;
                break;
            }
        }

        $winnerId = (int) $best['id'];
        if ($current && ((float) $current['score'] + $this->hysteresis()) >= (float) $best['score']) {
            $winnerId = (int) $current['id'];
        }

        $this->persistFallbackWinner($clubId, $winnerId);
    }

    private function persistFallbackWinner(?int $clubId, ?int $winnerId): void
    {
        $q = DB::table('computers')->where('kind', Computer::KIND_PC);
        if ($clubId) {
            $q->where('club_id', $clubId);
        }

        $rows = (clone $q)->get(['id', 'patch_seed_role', 'super_client', 'patch_seed_port']);
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $wantRole = $winnerId !== null && $id === $winnerId ? 'fallback' : null;
            $haveRole = (string) ($row->patch_seed_role ?? '') === 'fallback' ? 'fallback' : null;
            $patch = [];
            if ($wantRole !== $haveRole) {
                $patch['patch_seed_role'] = $wantRole;
            }
            if ($wantRole === null
                && ! filter_var($row->super_client ?? false, FILTER_VALIDATE_BOOLEAN)
                && (int) ($row->patch_seed_port ?? 0) > 0
                && $haveRole === 'fallback') {
                $patch['patch_seed_port'] = null;
            }
            if ($patch !== []) {
                $patch['updated_at'] = SqlTime::now();
                DB::table('computers')->where('id', $id)->update($patch);
            }
        }
    }

    private function seedScore(Computer $pc): float
    {
        $media = strtolower((string) ($pc->cache_media ?? 'unknown'));
        $mediaScore = match ($media) {
            'scm' => 4500.0,
            'nvme' => 4000.0,
            'ssd' => 2500.0,
            'hdd' => 0.0,
            default => 800.0,
        };

        $letter = strtoupper(substr((string) ($pc->volume_letter ?? ''), 0, 1));
        $root = strtoupper((string) ($pc->data_root ?? ''));
        $volumeScore = 0.0;
        if ($letter === 'D' || str_starts_with($root, 'D:')) {
            $volumeScore = 500.0;
        } elseif ($letter !== '') {
            $volumeScore = 50.0;
        }

        $health = strtolower((string) ($pc->ssd_health ?? ''));
        $healthPenalty = $health === 'warning' ? 150.0 : 0.0;

        return $mediaScore + $volumeScore + (float) ($pc->cache_free_gb ?? 0) - $healthPenalty;
    }

    public function clubHasOnlineSuperClient(?int $clubId): bool
    {
        $q = Computer::query()
            ->where('super_client', true)
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', now()->subSeconds(
                max(30, (int) config('club.power.heartbeat_stale_seconds', 180))
            ));
        if ($clubId) {
            $q->where('club_id', $clubId);
        }

        return $q->exists();
    }

    private function hasBusySession(int $computerId): bool
    {
        if ($computerId <= 0) {
            return false;
        }

        return Booking::query()
            ->where('computer_id', $computerId)
            ->where('status', 'active')
            ->exists();
    }

    private function featureOn(Computer $computer): bool
    {
        return app(ClubFeatureService::class)->enabledForComputer($computer, 'patch_cache');
    }
}
