<?php

namespace App\Services;

use App\Models\Computer;
use App\Models\GoldenImageRevision;
use App\Support\SqlTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Rollback Markers: архив хэшей манифестов Steam/Epic и конфигов
 * при сохранении Super Client; откат станции на проверенную ревизию.
 */
class GoldenImageRevisionService
{
    public const ACTION_ROLLBACK = 'rollback_revision';

    public const MAX_FILES = 280;

    public const MAX_BODY = 24576;

    public const KEEP = 24;

    public function ttlMinutes(): int
    {
        return 20;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: int, created: bool, status: string, changed_count: int}
     */
    public function isOn(?int $clubId): bool
    {
        return app(ClubFeatureService::class)->enabled($clubId, 'rollback_markers');
    }

    public function assertOn(?int $clubId): void
    {
        if (! $this->isOn($clubId)) {
            throw ValidationException::withMessages([
                'feature' => 'Rollback Markers выключены в конфигурации клуба.',
            ]);
        }
    }

    public function ingest(Computer $computer, array $payload): array
    {
        $this->assertOn($computer->club_id);
        $files = $this->normalizeFiles($payload['files'] ?? []);
        if ($files === []) {
            throw ValidationException::withMessages([
                'files' => 'Нет файлов для маркера ревизии.',
            ]);
        }

        $hash = strtolower(trim((string) ($payload['aggregate_hash'] ?? '')));
        if ($hash === '' || strlen($hash) > 64) {
            $hash = $this->aggregateHash($files);
        }

        $latest = $this->latestForClub($computer->club_id);
        if ($latest && strcasecmp((string) $latest->aggregate_hash, $hash) === 0) {
            DB::table('computers')->where('id', $computer->id)->update([
                'golden_revision_id' => $latest->id,
                'updated_at' => SqlTime::now(),
            ]);

            return [
                'id' => (int) $latest->id,
                'created' => false,
                'status' => (string) $latest->status,
                'changed_count' => (int) $latest->changed_count,
            ];
        }

        $prevMap = $this->fileHashMap($latest?->files ?? []);
        $changed = [];
        foreach ($files as $row) {
            $rel = $row['rel'];
            if (($prevMap[$rel] ?? '') !== $row['sha256']) {
                $changed[] = $rel;
            }
        }

        $isFirst = $latest === null;
        $autoVerify = $isFirst || app(ClubFeatureService::class)->bool(
            $computer->club_id,
            'rollback_markers',
            'auto_verify',
            false,
        );
        $now = now();
        if ($autoVerify && ! $isFirst) {
            DB::table('golden_image_revisions')
                ->where('club_id', $computer->club_id)
                ->where('status', GoldenImageRevision::STATUS_VERIFIED)
                ->update([
                    'status' => GoldenImageRevision::STATUS_SUPERSEDED,
                    'updated_at' => $now,
                ]);
        }
        $status = $autoVerify ? GoldenImageRevision::STATUS_VERIFIED : GoldenImageRevision::STATUS_PENDING;
        $id = DB::table('golden_image_revisions')->insertGetId([
            'club_id' => $computer->club_id,
            'computer_id' => $computer->id,
            'status' => $status,
            'disk_mode' => $this->diskMode($payload['disk_mode'] ?? null),
            'aggregate_hash' => $hash,
            'inventory_hash' => mb_substr((string) ($payload['inventory_hash'] ?? $computer->games_inventory_hash ?? ''), 0, 64) ?: null,
            'steam_count' => max(0, (int) ($payload['steam_count'] ?? $computer->games_steam_count ?? 0)),
            'epic_count' => max(0, (int) ($payload['epic_count'] ?? $computer->games_epic_count ?? 0)),
            'file_count' => count($files),
            'changed_count' => count($changed),
            'files' => json_encode($files, JSON_UNESCAPED_UNICODE),
            'changed_rels' => json_encode($changed, JSON_UNESCAPED_UNICODE),
            'note' => $this->note($payload['note'] ?? null),
            'verified_by' => null,
            'verified_at' => $autoVerify ? $now : null,
            'rolled_back_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('computers')->where('id', $computer->id)->update([
            'golden_revision_id' => $id,
            'updated_at' => SqlTime::now(),
        ]);

        $this->pruneClub((int) $computer->club_id);

        Log::info('Golden image revision stored', [
            'id' => $id,
            'computer_id' => $computer->id,
            'changed' => count($changed),
            'first' => $isFirst,
            'auto_verify' => $autoVerify,
        ]);

        return [
            'id' => $id,
            'created' => true,
            'status' => $status,
            'changed_count' => count($changed),
        ];
    }

    public function verify(GoldenImageRevision $revision, ?int $adminId = null): GoldenImageRevision
    {
        $this->assertOn($revision->club_id);
        if ($revision->status === GoldenImageRevision::STATUS_VERIFIED) {
            return $revision;
        }

        $now = now();
        DB::transaction(function () use ($revision, $adminId, $now) {
            DB::table('golden_image_revisions')
                ->where('club_id', $revision->club_id)
                ->where('status', GoldenImageRevision::STATUS_VERIFIED)
                ->where('id', '!=', $revision->id)
                ->update([
                    'status' => GoldenImageRevision::STATUS_SUPERSEDED,
                    'updated_at' => $now,
                ]);

            DB::table('golden_image_revisions')->where('id', $revision->id)->update([
                'status' => GoldenImageRevision::STATUS_VERIFIED,
                'verified_by' => $adminId,
                'verified_at' => $now,
                'updated_at' => $now,
            ]);
        });

        $revision->refresh();

        return $revision;
    }

    /**
     * @return array{command_id: int, action: string, revision_id: int}
     */
    public function enqueueRollback(
        Computer $computer,
        ?int $revisionId = null,
        ?CarbonImmutable $now = null,
    ): array {
        $now = $now ?? CarbonImmutable::now();
        $this->assertOn($computer->club_id);
        $revision = $revisionId
            ? GoldenImageRevision::query()->find($revisionId)
            : $this->latestVerified($computer->club_id);

        if (! $revision) {
            throw ValidationException::withMessages([
                'revision_id' => 'Нет проверенной ревизии золотого образа.',
            ]);
        }
        if ($computer->club_id && $revision->club_id && (int) $computer->club_id !== (int) $revision->club_id) {
            throw ValidationException::withMessages([
                'revision_id' => 'Ревизия из другой локации.',
            ]);
        }
        if (! in_array($revision->status, [
            GoldenImageRevision::STATUS_VERIFIED,
            GoldenImageRevision::STATUS_SUPERSEDED,
        ], true)) {
            throw ValidationException::withMessages([
                'revision_id' => 'Откат только на проверенную ревизию, не на свежий pending.',
            ]);
        }

        $power = app(ComputerPowerService::class);
        $stale = $power->staleSeconds();
        $online = $computer->last_seen_at
            && $computer->last_seen_at->greaterThanOrEqualTo($now->subSeconds($stale));
        if (! $online) {
            throw ValidationException::withMessages([
                'computer_id' => 'ПК офлайн — откат только на живом шелле.',
            ]);
        }

        $id = (int) ($computer->rollback_command_id ?? 0);
        $id = $id > 0 ? $id + 1 : (int) floor(microtime(true) * 1000);

        DB::table('computers')->where('id', $computer->id)->update([
            'rollback_command' => self::ACTION_ROLLBACK,
            'rollback_command_id' => $id,
            'rollback_revision_id' => $revision->id,
            'rollback_command_at' => $now,
            'rollback_result' => 'queued',
            'rollback_message' => null,
            'updated_at' => SqlTime::now(),
        ]);

        Log::warning('Golden image rollback queued', [
            'computer_id' => $computer->id,
            'revision_id' => $revision->id,
            'command_id' => $id,
        ]);

        return [
            'command_id' => $id,
            'action' => self::ACTION_ROLLBACK,
            'revision_id' => (int) $revision->id,
        ];
    }

    /**
     * @return array{command_id: int, action: string, revision_id: int}|null
     */
    public function pendingFor(Computer $computer): ?array
    {
        if (! $this->isOn($computer->club_id)) {
            return null;
        }
        $action = (string) ($computer->rollback_command ?? '');
        $id = (int) ($computer->rollback_command_id ?? 0);
        $revisionId = (int) ($computer->rollback_revision_id ?? 0);
        if ($action === '' || $id <= 0 || $revisionId <= 0) {
            return null;
        }

        $at = $computer->rollback_command_at;
        if ($at && $at->lessThan(CarbonImmutable::now()->subMinutes($this->ttlMinutes()))) {
            DB::table('computers')->where('id', $computer->id)->update([
                'rollback_command' => null,
                'rollback_command_id' => null,
                'rollback_command_at' => null,
                'rollback_result' => 'timeout',
                'rollback_message' => 'Шелл не подтвердил откат за '.$this->ttlMinutes().' мин',
                'updated_at' => SqlTime::now(),
            ]);
            $computer->rollback_command = null;
            $computer->rollback_command_id = null;

            return null;
        }

        return [
            'command_id' => $id,
            'action' => $action === '' ? self::ACTION_ROLLBACK : $action,
            'revision_id' => $revisionId,
        ];
    }

    public function ack(Computer $computer, ?int $ackId, ?string $result, ?string $message): void
    {
        if (! $ackId || $ackId <= 0) {
            return;
        }

        $patch = [
            'rollback_result' => $result ? mb_substr($result, 0, 32) : 'accepted',
            'rollback_message' => $message ? mb_substr($message, 0, 240) : null,
            'updated_at' => SqlTime::now(),
        ];

        if (in_array($result, ['ok', 'done'], true)) {
            $revId = (int) ($computer->rollback_revision_id ?? 0);
            if ($revId > 0) {
                $patch['golden_revision_id'] = $revId;
                DB::table('golden_image_revisions')->where('id', $revId)->update([
                    'rolled_back_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if ((int) $computer->rollback_command_id === $ackId && $result !== 'running') {
            $patch['rollback_command'] = null;
            $patch['rollback_command_id'] = null;
            $patch['rollback_command_at'] = null;
        }

        DB::table('computers')->where('id', $computer->id)->update($patch);
        Log::info('Golden image rollback acked', [
            'computer_id' => $computer->id,
            'ack_id' => $ackId,
            'result' => $result,
        ]);
    }

    /**
     * @return array{id: int, created: bool}
     */
    public function noteCrash(Computer $computer, ?string $reason, ?string $detail = null): array
    {
        if (! $this->isOn($computer->club_id)) {
            return ['id' => 0, 'created' => false];
        }
        $reason = strtolower(trim((string) $reason));
        if (! in_array($reason, ['bsod', 'driver', 'unexpected'], true)) {
            $reason = 'unexpected';
        }
        $detail = $detail ? mb_substr(trim($detail), 0, 240) : null;

        DB::table('computers')->where('id', $computer->id)->update([
            'last_crash_at' => SqlTime::now(),
            'last_crash_reason' => $reason,
            'last_crash_detail' => $detail,
            'updated_at' => SqlTime::now(),
        ]);

        $ticket = app(ClubFeatureService::class)->bool(
            $computer->club_id,
            'rollback_markers',
            'crash_ticket',
            true,
        );
        if (! $ticket) {
            return ['id' => 0, 'created' => false];
        }

        $recorded = app(ShellIncidentService::class)->record(
            $computer,
            ShellIncidentService::TYPE_GOLDEN_CRASH,
            '',
            'high',
            [
                'reason' => $reason,
                'detail' => $detail,
                'verified_revision_id' => $this->latestVerified($computer->club_id)?->id,
            ],
        );

        return $recorded;
    }

    public function payloadFor(GoldenImageRevision $revision): array
    {
        $files = is_array($revision->files) ? $revision->files : [];

        return [
            'id' => (int) $revision->id,
            'status' => (string) $revision->status,
            'disk_mode' => $revision->disk_mode,
            'aggregate_hash' => $revision->aggregate_hash,
            'steam_count' => (int) $revision->steam_count,
            'epic_count' => (int) $revision->epic_count,
            'file_count' => (int) $revision->file_count,
            'changed_count' => (int) $revision->changed_count,
            'changed_rels' => $revision->changed_rels ?? [],
            'note' => $revision->note,
            'created_at' => optional($revision->created_at)?->toIso8601String(),
            'verified_at' => optional($revision->verified_at)?->toIso8601String(),
            'files' => $files,
        ];
    }

    public function latestVerified(?int $clubId): ?GoldenImageRevision
    {
        return GoldenImageRevision::query()
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->where('status', GoldenImageRevision::STATUS_VERIFIED)
            ->orderByDesc('id')
            ->first();
    }

    public function latestForClub(?int $clubId): ?GoldenImageRevision
    {
        return GoldenImageRevision::query()
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  Collection<int, mixed>  $computers
     * @return Collection<int, mixed>
     */
    public function decorateSnapshot(Collection $computers): Collection
    {
        $clubIds = $computers->map(function ($pc) {
            return (int) (is_array($pc) ? ($pc['club_id'] ?? 0) : ($pc->club_id ?? 0));
        })->filter()->unique()->values()->all();

        if ($clubIds === []) {
            return $computers;
        }

        $rows = GoldenImageRevision::query()
            ->whereIn('club_id', $clubIds)
            ->orderByDesc('id')
            ->get(['id', 'club_id', 'status', 'aggregate_hash', 'changed_count', 'created_at', 'verified_at']);

        $verified = [];
        $pending = [];
        foreach ($rows as $row) {
            $cid = (int) $row->club_id;
            if ($row->status === GoldenImageRevision::STATUS_VERIFIED && ! isset($verified[$cid])) {
                $verified[$cid] = $row;
            }
            if ($row->status === GoldenImageRevision::STATUS_PENDING && ! isset($pending[$cid])) {
                $pending[$cid] = $row;
            }
        }

        return $computers->map(function ($pc) use ($verified, $pending) {
            $cid = (int) (is_array($pc) ? ($pc['club_id'] ?? 0) : ($pc->club_id ?? 0));
            $ver = $verified[$cid] ?? null;
            $pen = $pending[$cid] ?? null;
            $fields = [
                'verified_revision_id' => $ver?->id,
                'verified_revision_hash' => $ver ? substr((string) $ver->aggregate_hash, 0, 12) : null,
                'verified_revision_at' => optional($ver?->verified_at ?? $ver?->created_at)?->toIso8601String(),
                'pending_revision_id' => $pen?->id,
                'pending_revision_changed' => $pen ? (int) $pen->changed_count : null,
                'can_rollback' => $ver !== null
                    && app(ClubFeatureService::class)->enabled($cid ?: null, 'rollback_markers'),
            ];
            if (is_array($pc)) {
                return array_merge($pc, $fields);
            }
            foreach ($fields as $k => $v) {
                $pc->{$k} = $v;
            }

            return $pc;
        });
    }

    private function pruneClub(int $clubId): void
    {
        if ($clubId <= 0) {
            return;
        }
        $keep = max(4, app(ClubFeatureService::class)->int($clubId, 'rollback_markers', 'keep', self::KEEP));
        $ids = GoldenImageRevision::query()
            ->where('club_id', $clubId)
            ->orderByDesc('id')
            ->skip($keep)
            ->take(200)
            ->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }
        GoldenImageRevision::query()
            ->whereIn('id', $ids)
            ->where('status', '!=', GoldenImageRevision::STATUS_VERIFIED)
            ->delete();
    }

    /**
     * @param  mixed  $raw
     * @return list<array{rel: string, sha256: string, kind: string, body: ?string}>
     */
    private function normalizeFiles(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach (array_slice(array_values($raw), 0, self::MAX_FILES) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rel = str_replace('\\', '/', strtolower(trim((string) ($row['rel'] ?? ''))));
            $rel = ltrim($rel, '/');
            $sha = strtolower(trim((string) ($row['sha256'] ?? $row['hash'] ?? '')));
            $kind = strtolower(trim((string) ($row['kind'] ?? 'config')));
            if ($rel === '' || strlen($rel) > 260 || ! preg_match('/^[a-f0-9]{32,64}$/', $sha)) {
                continue;
            }
            if (! in_array($kind, ['steam_manifest', 'steam_config', 'epic_item', 'epic_config', 'shell_config', 'config'], true)) {
                $kind = 'config';
            }
            if (isset($seen[$rel])) {
                continue;
            }
            $seen[$rel] = true;
            $body = $row['body'] ?? null;
            if (is_string($body)) {
                if (strlen($body) > self::MAX_BODY || $body === '') {
                    $body = null;
                }
            } else {
                $body = null;
            }
            $out[] = [
                'rel' => $rel,
                'sha256' => $sha,
                'kind' => $kind,
                'body' => $body,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{rel: string, sha256: string, kind: string, body: ?string}>  $files
     */
    private function aggregateHash(array $files): string
    {
        $parts = [];
        foreach ($files as $row) {
            $parts[] = $row['rel'].'='.$row['sha256'];
        }
        sort($parts);

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * @param  mixed  $files
     * @return array<string, string>
     */
    private function fileHashMap(mixed $files): array
    {
        if (! is_array($files)) {
            return [];
        }
        $map = [];
        foreach ($files as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rel = strtolower((string) ($row['rel'] ?? ''));
            $sha = strtolower((string) ($row['sha256'] ?? ''));
            if ($rel !== '' && $sha !== '') {
                $map[$rel] = $sha;
            }
        }

        return $map;
    }

    private function diskMode(mixed $mode): string
    {
        $mode = strtolower(trim((string) $mode));

        return in_array($mode, ['image', 'disk', 'both'], true) ? $mode : 'image';
    }

    private function note(mixed $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : mb_substr($note, 0, 240);
    }
}
