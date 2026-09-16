<?php

namespace App\Services;

use App\Models\StoreAssemblyClipJob;
use App\Models\StoreBuiltPc;
use App\Models\VideoSurveillanceSetting;
use App\Services\Hikvision\HikvisionIsapiMarker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class StoreAssemblyCaptureService
{
    public const MAX_BYTES = 96 * 1024 * 1024;

    public function __construct(private VideoMarkerService $markers) {}

    public function onAssemblyStarted(StoreBuiltPc $pc): void
    {
        $pc->refresh();
        $first = ! $pc->assembly_started_at;
        $channel = $this->channelFor($pc);
        $updates = [];
        if ($first) {
            $updates['assembly_started_at'] = now();
        }
        if ($channel && ! $pc->nvr_channel) {
            $updates['nvr_channel'] = $channel;
        }
        if ($updates !== []) {
            $pc->update($updates);
            $pc->refresh();
        }
        if (! $first) {
            return;
        }

        $this->markers->placeMarkerForTrigger('store.assembly_start', [
            'title' => $this->markerTitle($pc, 'Сборка'),
            'channel' => $channel,
            'meta' => [
                'built_pc_id' => $pc->id,
                'serial' => $pc->serial_number,
            ],
        ], $pc->club_id ? (int) $pc->club_id : null);
    }

    public function onAssemblyFinished(StoreBuiltPc $pc): void
    {
        $pc->refresh();
        if (! $pc->assembly_started_at) {
            $this->onAssemblyStarted($pc);
            $pc->refresh();
        }

        $first = ! $pc->assembly_finished_at;
        if ($first) {
            $pc->update(['assembly_finished_at' => now()]);
            $pc->refresh();
        }
        if (! $first) {
            $this->enqueueClipExport($pc);

            return;
        }

        $channel = $this->channelFor($pc);
        $this->markers->placeMarkerForTrigger('store.assembly_done', [
            'title' => $this->markerTitle($pc, 'Готов'),
            'channel' => $channel,
            'meta' => [
                'built_pc_id' => $pc->id,
                'serial' => $pc->serial_number,
            ],
        ], $pc->club_id ? (int) $pc->club_id : null);

        $this->enqueueClipExport($pc);
    }

    public function enqueueClipExport(StoreBuiltPc $pc): ?StoreAssemblyClipJob
    {
        $pc->refresh();
        if ($pc->hasAssemblyClip()) {
            return null;
        }

        $start = $pc->assembly_started_at;
        $end = $pc->assembly_finished_at ?: now();
        if (! $start) {
            return null;
        }

        $open = StoreAssemblyClipJob::query()
            ->where('store_built_pc_id', $pc->id)
            ->whereIn('status', [
                StoreAssemblyClipJob::STATUS_PENDING,
                StoreAssemblyClipJob::STATUS_CLAIMED,
                StoreAssemblyClipJob::STATUS_SENT,
            ])
            ->exists();
        if ($open) {
            return null;
        }

        $maxMin = max(5, (int) config('store.assembly_clip_max_minutes', 20));
        if ($start->diffInMinutes($end) > $maxMin) {
            $end = $start->copy()->addMinutes($maxMin);
        }

        $channel = $this->channelFor($pc);
        $trackId = HikvisionIsapiMarker::trackId($channel) ?? HikvisionIsapiMarker::trackId('1') ?? 101;

        return StoreAssemblyClipJob::query()->create([
            'club_id' => $pc->club_id,
            'store_built_pc_id' => $pc->id,
            'status' => StoreAssemblyClipJob::STATUS_PENDING,
            'channel' => $channel,
            'track_id' => $trackId,
            'starts_at' => $start,
            'ends_at' => $end,
        ]);
    }

    public function storeClip(StoreBuiltPc $pc, UploadedFile $file): StoreBuiltPc
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if ($ext !== 'mp4') {
            throw ValidationException::withMessages(['clip' => 'Нужен файл .mp4']);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['clip' => 'Ролик больше 96 МБ — сожмите запись.']);
        }

        $dir = 'assembly-clips/'.$pc->id;
        $name = 'build.mp4';
        if ($pc->assembly_clip_path) {
            Storage::disk('local')->delete($pc->assembly_clip_path);
        }
        $path = $file->storeAs($dir, $name, 'local');
        if (! $path) {
            throw ValidationException::withMessages(['clip' => 'Не удалось сохранить ролик.']);
        }

        $pc->update([
            'assembly_clip_path' => $path,
            'assembly_clip_bytes' => (int) $file->getSize(),
            'assembly_clip_uploaded_at' => now(),
        ]);

        StoreAssemblyClipJob::query()
            ->where('store_built_pc_id', $pc->id)
            ->whereIn('status', [
                StoreAssemblyClipJob::STATUS_PENDING,
                StoreAssemblyClipJob::STATUS_CLAIMED,
            ])
            ->update([
                'status' => StoreAssemblyClipJob::STATUS_SENT,
                'sent_at' => now(),
            ]);

        return $pc->fresh();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function claimPending(int $limit = 5, ?int $clubId = null): array
    {
        $limit = max(1, min(20, $limit));
        $this->releaseStaleClaims((int) config('video_surveillance.stale_claim_minutes', 2));

        return DB::transaction(function () use ($limit, $clubId) {
            $q = StoreAssemblyClipJob::query()
                ->with('builtPc.warranty:id,store_built_pc_id,public_token,serial')
                ->where('status', StoreAssemblyClipJob::STATUS_PENDING)
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate();
            if ($clubId) {
                $q->where('club_id', $clubId);
            }

            /** @var Collection<int, StoreAssemblyClipJob> $jobs */
            $jobs = $q->get();
            $out = [];
            foreach ($jobs as $job) {
                $payload = $this->agentJobPayload($job);
                if ($payload === null) {
                    $job->status = StoreAssemblyClipJob::STATUS_FAILED;
                    $job->last_error = 'NVR api_base_url пуст';
                    $job->attempts = (int) $job->attempts + 1;
                    $job->save();

                    continue;
                }

                $job->status = StoreAssemblyClipJob::STATUS_CLAIMED;
                $job->claimed_at = now();
                $job->attempts = (int) $job->attempts + 1;
                $job->save();
                $out[] = $payload;
            }

            return $out;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function agentJobPayload(StoreAssemblyClipJob $job): ?array
    {
        $s = $this->markers->settings($job->club_id ? (int) $job->club_id : null);
        $base = rtrim((string) $s->api_base_url, '/');
        if ($base === '') {
            return null;
        }

        $pc = $job->builtPc;
        $channel = (string) ($job->channel ?: $s->default_channel ?: '1');
        $trackId = $job->track_id ?: (HikvisionIsapiMarker::trackId($channel) ?? 101);
        $start = $job->starts_at ?? now()->subMinutes(10);
        $end = $job->ends_at ?? $start->copy()->addMinutes(10);
        $host = parse_url($base, PHP_URL_HOST) ?: '';
        $startUtc = $start->copy()->utc()->format('Ymd\tHis\z');
        $endUtc = $end->copy()->utc()->format('Ymd\tHis\z');

        return [
            'id' => (int) $job->id,
            'built_pc_id' => (int) $job->store_built_pc_id,
            'serial' => $pc?->serial_number,
            'channel' => $channel,
            'track_id' => $trackId,
            'starts_at' => HikvisionIsapiMarker::formatTime($start),
            'ends_at' => HikvisionIsapiMarker::formatTime($end),
            'max_seconds' => max(60, (int) $start->diffInSeconds($end)),
            'rtsp' => $host !== ''
                ? 'rtsp://{login}:{password}@'.$host.':554/Streaming/tracks/'.$trackId
                    .'?starttime='.$startUtc.'&endtime='.$endUtc
                : null,
            'upload' => [
                'url' => url('/api/video/assembly-clips'),
                'field' => 'clip',
            ],
        ];
    }

    /**
     * @param  list<int>  $ids
     */
    public function markSent(array $ids): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }

        return StoreAssemblyClipJob::query()
            ->whereIn('id', $ids)
            ->whereIn('status', [
                StoreAssemblyClipJob::STATUS_CLAIMED,
                StoreAssemblyClipJob::STATUS_PENDING,
            ])
            ->update([
                'status' => StoreAssemblyClipJob::STATUS_SENT,
                'sent_at' => now(),
                'last_error' => null,
            ]);
    }

    /**
     * @param  list<array{id:int,error?:string|null}>  $rows
     */
    public function markFailed(array $rows): int
    {
        $n = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $n += StoreAssemblyClipJob::query()->whereKey($id)->update([
                'status' => StoreAssemblyClipJob::STATUS_FAILED,
                'last_error' => mb_substr((string) ($row['error'] ?? 'error'), 0, 500),
            ]);
        }

        return $n;
    }

    public function releaseStaleClaims(int $minutes = 2): int
    {
        $before = now()->subMinutes(max(1, $minutes));

        return StoreAssemblyClipJob::query()
            ->where('status', StoreAssemblyClipJob::STATUS_CLAIMED)
            ->where('claimed_at', '<', $before)
            ->update([
                'status' => StoreAssemblyClipJob::STATUS_PENDING,
                'claimed_at' => null,
            ]);
    }

    public function channelFor(StoreBuiltPc $pc): string
    {
        $own = trim((string) ($pc->nvr_channel ?? ''));
        if ($own !== '') {
            return $own;
        }

        $fromConfig = trim((string) config('store.assembly_nvr_channel', ''));
        if ($fromConfig !== '') {
            return $fromConfig;
        }

        $s = VideoSurveillanceSetting::forClub($pc->club_id ? (int) $pc->club_id : null);

        return trim((string) ($s->default_channel ?: '1'));
    }

    private function markerTitle(StoreBuiltPc $pc, string $prefix): string
    {
        $sn = trim((string) ($pc->serial_number ?? ''));

        return $sn !== '' ? $prefix.' '.$sn : $prefix.' ПК#'.$pc->id;
    }
}
