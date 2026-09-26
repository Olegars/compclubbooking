<?php

namespace App\Services;

use App\Models\Computer;
use App\Models\IncidentClipJob;
use App\Services\Hikvision\HikvisionIsapiMarker;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Вырезка эпизода с камеры ПК: T-30с … T+15с, через RTSP playback NVR.
 * Облако до регистратора не ходит — файл привозит LAN-агент.
 */
class IncidentClipService
{
    public const MAX_BYTES = 96 * 1024 * 1024;

    public function __construct(private VideoMarkerService $markers) {}

    /**
     * @return array{status:string,job_id:?int,file_name:?string,error:?string,play_url:?string}
     */
    public static function emptyClip(): array
    {
        return [
            'status' => 'none',
            'job_id' => null,
            'file_name' => null,
            'error' => null,
            'play_url' => null,
        ];
    }

    public function enqueue(
        string $subjectType,
        int $subjectId,
        Computer $computer,
        DateTimeInterface $eventAt,
        string $slug,
    ): ?IncidentClipJob {
        if ($subjectId < 1) {
            return null;
        }

        $clubId = $computer->club_id ? (int) $computer->club_id : null;
        $settings = $this->markers->settings($clubId);
        if (! $settings->is_enabled || $settings->provider !== 'hikvision') {
            return null;
        }

        $exists = IncidentClipJob::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->exists();
        if ($exists) {
            return null;
        }

        $event = Carbon::parse($eventAt);
        $pre = max(0, (int) config('video_surveillance.incident_clip_pre_sec', 30));
        $post = max(1, (int) config('video_surveillance.incident_clip_post_sec', 15));
        $start = $event->copy()->subSeconds($pre);
        $end = $event->copy()->addSeconds($post);

        $covered = IncidentClipJob::query()
            ->where('computer_id', $computer->id)
            ->whereIn('status', [
                IncidentClipJob::STATUS_PENDING,
                IncidentClipJob::STATUS_CLAIMED,
                IncidentClipJob::STATUS_SENT,
            ])
            ->where('event_at', '>=', $event->copy()->subSeconds($pre + $post))
            ->where('event_at', '<=', $event->copy()->addSeconds($pre + $post))
            ->exists();
        if ($covered) {
            return null;
        }

        $channel = trim((string) ($computer->nvr_channel ?? ''));
        $trackId = HikvisionIsapiMarker::trackId($channel !== '' ? $channel : null);
        $fileName = $this->fileName($subjectId, $computer, $slug, $event);

        $job = IncidentClipJob::query()->create([
            'club_id' => $settings->club_id ?: $clubId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'computer_id' => $computer->id,
            'status' => IncidentClipJob::STATUS_PENDING,
            'channel' => $channel !== '' ? $channel : null,
            'track_id' => $trackId,
            'event_at' => $event,
            'starts_at' => $start,
            'ends_at' => $end,
            'file_name' => $fileName,
        ]);

        if ($channel === '' || $trackId === null) {
            $job->status = IncidentClipJob::STATUS_FAILED;
            $job->attempts = 1;
            $job->last_error = 'Канал NVR для этого ПК не задан';
            $job->save();
        }

        $this->syncIncidentRow($job);

        return $job;
    }

    public function storeClip(IncidentClipJob $job, UploadedFile $file): IncidentClipJob
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if ($ext !== 'mp4') {
            throw ValidationException::withMessages(['clip' => 'Нужен файл .mp4']);
        }
        $max = (int) config('video_surveillance.incident_clip_max_kb', 98304) * 1024;
        $max = $max > 0 ? $max : self::MAX_BYTES;
        if ($file->getSize() > $max) {
            throw ValidationException::withMessages(['clip' => 'Ролик больше допустимого размера.']);
        }

        $name = $this->safeFileName($job->file_name ?: ('inc_'.$job->id.'.mp4'));
        $dir = 'incident-clips/'.$job->id;
        if ($job->file_path) {
            Storage::disk('local')->delete($job->file_path);
        }
        $path = $file->storeAs($dir, $name, 'local');
        if (! $path) {
            throw ValidationException::withMessages(['clip' => 'Не удалось сохранить ролик.']);
        }

        $job->file_name = $name;
        $job->file_path = $path;
        $job->bytes = (int) $file->getSize();
        $job->status = IncidentClipJob::STATUS_SENT;
        $job->sent_at = now();
        $job->last_error = null;
        $job->save();

        $this->syncIncidentRow($job);

        return $job;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function claimPending(int $limit = 2, ?int $clubId = null): array
    {
        $limit = max(1, min(3, $limit));
        $this->releaseStaleClaims((int) config('video_surveillance.incident_clip_stale_minutes', 4));

        return DB::transaction(function () use ($limit, $clubId) {
            $q = IncidentClipJob::query()
                ->where('status', IncidentClipJob::STATUS_PENDING)
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate();
            if ($clubId) {
                $q->where('club_id', $clubId);
            }

            /** @var Collection<int, IncidentClipJob> $jobs */
            $jobs = $q->get();
            $out = [];
            foreach ($jobs as $job) {
                $payload = $this->agentJobPayload($job);
                if ($payload === null) {
                    $job->status = IncidentClipJob::STATUS_FAILED;
                    $job->last_error = $job->last_error ?: 'NVR api_base_url пуст';
                    $job->attempts = (int) $job->attempts + 1;
                    $job->save();
                    $this->syncIncidentRow($job);

                    continue;
                }

                $job->status = IncidentClipJob::STATUS_CLAIMED;
                $job->claimed_at = now();
                $job->attempts = (int) $job->attempts + 1;
                $job->save();
                $this->syncIncidentRow($job);
                $out[] = $payload;
            }

            return $out;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function agentJobPayload(IncidentClipJob $job): ?array
    {
        if ($job->status === IncidentClipJob::STATUS_FAILED && ! $job->track_id) {
            return null;
        }

        $s = $this->markers->settings($job->club_id ? (int) $job->club_id : null);
        $base = rtrim((string) $s->api_base_url, '/');
        if ($base === '' || ! $job->track_id || ! $job->starts_at || ! $job->ends_at) {
            return null;
        }

        $host = parse_url($base, PHP_URL_HOST) ?: '';
        $startUtc = HikvisionIsapiMarker::playbackStamp($job->starts_at);
        $endUtc = HikvisionIsapiMarker::playbackStamp($job->ends_at);
        $seconds = max(5, (int) abs($job->starts_at->diffInSeconds($job->ends_at)));

        return [
            'id' => (int) $job->id,
            'job_id' => (int) $job->id,
            'incident_id' => $job->subject_type === IncidentClipJob::SUBJECT_INCIDENT
                ? (int) $job->subject_id
                : null,
            'subject_type' => $job->subject_type,
            'subject_id' => (int) $job->subject_id,
            'nvr_channel' => (string) $job->channel,
            'track_id' => (int) $job->track_id,
            'start_time_iso' => $job->starts_at->toIso8601String(),
            'end_time_iso' => $job->ends_at->toIso8601String(),
            'file_name_target' => $job->file_name,
            'max_seconds' => $seconds,
            'rtsp' => $host !== ''
                ? 'rtsp://{login}:{password}@'.$host.':554/Streaming/tracks/'.$job->track_id
                    .'?starttime='.$startUtc.'&endtime='.$endUtc
                : null,
            'upload' => [
                'url' => url('/api/video/incident-clips'),
                'field' => 'clip',
            ],
        ];
    }

    /**
     * @param  list<int>  $ids
     */
    public function markSent(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return 0;
        }

        $jobs = IncidentClipJob::query()
            ->whereIn('id', $ids)
            ->whereIn('status', [
                IncidentClipJob::STATUS_CLAIMED,
                IncidentClipJob::STATUS_PENDING,
            ])
            ->get();

        foreach ($jobs as $job) {
            if (! $job->file_path) {
                continue;
            }
            $job->status = IncidentClipJob::STATUS_SENT;
            $job->sent_at = $job->sent_at ?: now();
            $job->last_error = null;
            $job->save();
            $this->syncIncidentRow($job);
        }

        return $jobs->count();
    }

    /**
     * @param  list<array{id?:int,error?:string|null}>  $rows
     */
    public function markFailed(array $rows): int
    {
        $n = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $job = IncidentClipJob::query()->find($id);
            if (! $job) {
                continue;
            }
            $job->status = IncidentClipJob::STATUS_FAILED;
            $job->last_error = mb_substr((string) ($row['error'] ?? 'ffmpeg timeout'), 0, 500);
            $job->save();
            $this->syncIncidentRow($job);
            $n++;
        }

        return $n;
    }

    public function releaseStaleClaims(int $minutes = 4): int
    {
        $before = now()->subMinutes(max(1, $minutes));
        $maxAttempts = max(1, (int) config('video_surveillance.incident_clip_max_attempts', 3));
        $stale = IncidentClipJob::query()
            ->where('status', IncidentClipJob::STATUS_CLAIMED)
            ->where('claimed_at', '<', $before)
            ->get();

        $released = 0;
        foreach ($stale as $job) {
            if ((int) $job->attempts >= $maxAttempts) {
                $job->status = IncidentClipJob::STATUS_FAILED;
                $job->last_error = 'NVR не отдал ролик, попытки исчерпаны';
            } else {
                $job->status = IncidentClipJob::STATUS_PENDING;
                $job->claimed_at = null;
                $released++;
            }
            $job->save();
            $this->syncIncidentRow($job);
        }

        return $released;
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     * @return array<string, array{status:string,job_id:?int,file_name:?string,error:?string,play_url:?string}>
     */
    public function feedClips(iterable $rows): array
    {
        $byType = [];
        foreach ($rows as $row) {
            $parsed = $this->parseFeedId((string) ($row['id'] ?? ''));
            if ($parsed === null) {
                continue;
            }
            $byType[$parsed[0]][] = $parsed[1];
        }
        if ($byType === []) {
            return [];
        }

        $jobs = IncidentClipJob::query()
            ->where(function ($q) use ($byType) {
                foreach ($byType as $type => $ids) {
                    $q->orWhere(function ($inner) use ($type, $ids) {
                        $inner->where('subject_type', $type)->whereIn('subject_id', array_values(array_unique($ids)));
                    });
                }
            })
            ->orderByDesc('id')
            ->get();

        $map = [];
        foreach ($jobs as $job) {
            $key = $job->subject_type.':'.$job->subject_id;
            if (! isset($map[$key])) {
                $map[$key] = $this->present($job);
            }
        }

        return $map;
    }

    public function feedKey(string $feedId): ?string
    {
        $parsed = $this->parseFeedId($feedId);

        return $parsed ? $parsed[0].':'.$parsed[1] : null;
    }

    /**
     * @return array{status:string,job_id:?int,file_name:?string,error:?string,play_url:?string}
     */
    public function present(IncidentClipJob $job): array
    {
        $status = match ($job->status) {
            IncidentClipJob::STATUS_PENDING => 'pending',
            IncidentClipJob::STATUS_CLAIMED => 'processing',
            IncidentClipJob::STATUS_SENT => $job->file_path ? 'ready' : 'failed',
            IncidentClipJob::STATUS_FAILED => 'failed',
            default => 'none',
        };

        return [
            'status' => $status,
            'job_id' => (int) $job->id,
            'file_name' => $job->file_name,
            'error' => $status === 'failed' ? $job->last_error : null,
            'play_url' => $status === 'ready' ? '/admin/incidents/clips/'.$job->id : null,
        ];
    }

    public function syncIncidentRow(IncidentClipJob $job): void
    {
        if ($job->subject_type !== IncidentClipJob::SUBJECT_INCIDENT) {
            return;
        }

        $presented = $this->present($job);
        DB::table('incidents')->where('id', $job->subject_id)->update([
            'clip_status' => $presented['status'],
            'clip_file_name' => $job->file_name,
            'clip_url' => $presented['play_url'],
            'clip_path' => $job->file_path,
            'updated_at' => now(),
        ]);
    }

    private function fileName(int $subjectId, Computer $computer, string $slug, Carbon $event): string
    {
        $pc = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', (string) ($computer->name ?: '')));
        if ($pc === '') {
            $pc = 'pc'.$computer->id;
        }
        $type = strtolower((string) preg_replace('/[^a-z0-9_]+/i', '', $slug));
        if ($type === '') {
            $type = 'event';
        }

        return $this->safeFileName(sprintf(
            'inc_%d_%s_%s_%s.mp4',
            $subjectId,
            $pc,
            $type,
            $event->format('Ymd_His'),
        ));
    }

    private function safeFileName(string $name): string
    {
        $name = strtolower((string) preg_replace('/[^a-z0-9._-]+/i', '', $name));
        if (! str_ends_with($name, '.mp4')) {
            $name .= '.mp4';
        }

        return $name !== '.mp4' ? $name : 'incident.mp4';
    }

    /**
     * @return array{0:string,1:int}|null
     */
    private function parseFeedId(string $id): ?array
    {
        if (! preg_match('/^(inc|sos|hid)-(\d+)$/', $id, $m)) {
            return null;
        }

        $type = match ($m[1]) {
            'sos' => IncidentClipJob::SUBJECT_SOS,
            'hid' => IncidentClipJob::SUBJECT_HID,
            default => IncidentClipJob::SUBJECT_INCIDENT,
        };

        return [$type, (int) $m[2]];
    }
}
