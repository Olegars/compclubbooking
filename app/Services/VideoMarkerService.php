<?php

namespace App\Services;

use App\Models\VideoSurveillanceEvent;
use App\Models\VideoSurveillanceMarkerJob;
use App\Models\VideoSurveillanceSetting;
use App\Services\Hikvision\HikvisionIsapiMarker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ставит метки на таймлайне видеосервера по событиям из админки.
 *
 * generic_webhook — HTTP JSON с облака.
 * hikvision — очередь для LAN-агента (ISAPI Digest на DS-77xxNI-M4).
 */
class VideoMarkerService
{
    public function settings(?int $clubId = null): VideoSurveillanceSetting
    {
        return VideoSurveillanceSetting::forClub($clubId);
    }

    public function placeMarkerForTrigger(string $triggerKey, array $payload = [], ?int $clubId = null): bool
    {
        $s = $this->settings($clubId);
        if (! $s->is_enabled) {
            return false;
        }

        $events = VideoSurveillanceEvent::query()
            ->where('club_id', $s->club_id)
            ->where('is_enabled', true)
            ->where('trigger_key', $triggerKey)
            ->orderBy('sort')
            ->get();

        if ($events->isEmpty()) {
            return false;
        }

        $any = false;
        foreach ($events as $event) {
            $title = $payload['title']
                ?? ($event->marker_title ?: $event->name);

            if ($this->dispatchMarker($s, [
                'event' => $event->code,
                'title' => $title,
                'channel' => $payload['channel'] ?? $event->channel ?? $s->default_channel,
                'at' => $payload['at'] ?? now(),
                'meta' => array_merge($payload['meta'] ?? [], [
                    'trigger' => $triggerKey,
                    'event_id' => $event->id,
                ]),
            ])) {
                $any = true;
            }
        }

        return $any;
    }

    /**
     * Прямая отправка метки (тест из админки) — без проверки event flags.
     *
     * @param  array{event?:string,title?:string,channel?:string,at?:\DateTimeInterface|string|null,meta?:array}  $payload
     */
    public function placeMarker(array $payload, ?int $clubId = null): bool
    {
        $s = $this->settings($clubId);
        if (! $s->is_enabled) {
            return false;
        }

        return $this->dispatchMarker($s, $payload);
    }

    /**
     * @param  array{event?:string,title?:string,channel?:string,at?:\DateTimeInterface|string|null,meta?:array}  $payload
     */
    private function dispatchMarker(VideoSurveillanceSetting $s, array $payload): bool
    {
        $at = $payload['at'] ?? now();
        if (is_string($at)) {
            $at = now()->parse($at);
        }

        $duration = max(1, (int) $s->marker_duration_sec);
        $pre = max(0, (int) $s->marker_pre_sec);
        $start = $at->copy()->subSeconds($pre);

        $body = [
            'title' => (string) ($payload['title'] ?? 'Reactor marker'),
            'event' => (string) ($payload['event'] ?? ''),
            'channel' => (string) ($payload['channel'] ?? $s->default_channel ?? ''),
            'starts_at' => $start,
            'ends_at' => $start->copy()->addSeconds($duration),
            'duration_sec' => $duration,
            'pre_sec' => $pre,
            'meta' => $payload['meta'] ?? [],
        ];

        try {
            if ($s->provider === 'hikvision') {
                return $this->enqueueHikvision($s, $body);
            }

            return $this->sendWebhook($s, [
                'title' => $body['title'],
                'event' => $body['event'],
                'channel' => $body['channel'],
                'starts_at' => $body['starts_at']->toIso8601String(),
                'ends_at' => $body['ends_at']->toIso8601String(),
                'duration_sec' => $body['duration_sec'],
                'pre_sec' => $body['pre_sec'],
                'meta' => $body['meta'],
            ]);
        } catch (Throwable $e) {
            Log::warning('VideoMarkerService: failed', [
                'error' => $e->getMessage(),
                'event' => $body['event'],
                'provider' => $s->provider,
            ]);

            return false;
        }
    }

    /**
     * @param  array{title:string,event:string,channel:string,starts_at:\Illuminate\Support\Carbon,ends_at:\Illuminate\Support\Carbon,duration_sec:int,pre_sec:int,meta:array}  $body
     */
    private function enqueueHikvision(VideoSurveillanceSetting $s, array $body): bool
    {
        $base = rtrim((string) $s->api_base_url, '/');
        if ($base === '') {
            Log::info('VideoMarkerService: hikvision dry-run (no api_base_url)', [
                'title' => $body['title'],
                'channel' => $body['channel'],
            ]);

            return true;
        }

        $trackId = HikvisionIsapiMarker::trackId($body['channel']);

        VideoSurveillanceMarkerJob::create([
            'club_id' => $s->club_id,
            'status' => VideoSurveillanceMarkerJob::STATUS_PENDING,
            'title' => mb_substr($body['title'], 0, 191),
            'event' => mb_substr($body['event'], 0, 64) ?: null,
            'channel' => $body['channel'] !== '' ? $body['channel'] : null,
            'track_id' => $trackId,
            'starts_at' => $body['starts_at'],
            'ends_at' => $body['ends_at'],
            'duration_sec' => $body['duration_sec'],
            'pre_sec' => $body['pre_sec'],
            'meta' => $body['meta'],
        ]);

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function claimPending(int $limit = 10, ?int $clubId = null): array
    {
        $limit = max(1, min(50, $limit));

        return DB::transaction(function () use ($limit, $clubId) {
            /** @var Collection<int, VideoSurveillanceMarkerJob> $jobs */
            $q = VideoSurveillanceMarkerJob::query()
                ->where('status', VideoSurveillanceMarkerJob::STATUS_PENDING)
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate();
            if ($clubId) {
                $q->where('club_id', $clubId);
            }

            $jobs = $q->get();

            $out = [];
            foreach ($jobs as $job) {
                $payload = $this->agentJobPayload($job);
                if ($payload === null) {
                    $job->status = VideoSurveillanceMarkerJob::STATUS_FAILED;
                    $job->last_error = 'NVR api_base_url пуст';
                    $job->attempts = (int) $job->attempts + 1;
                    $job->save();

                    continue;
                }

                $job->status = VideoSurveillanceMarkerJob::STATUS_CLAIMED;
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
    public function agentJobPayload(VideoSurveillanceMarkerJob $job): ?array
    {
        $s = $this->settings($job->club_id ? (int) $job->club_id : null);
        $base = rtrim((string) $s->api_base_url, '/');
        if ($base === '') {
            return null;
        }

        $channel = (string) ($job->channel ?: $s->default_channel ?: '');
        $trackId = $job->track_id ?: HikvisionIsapiMarker::trackId($channel);
        if (! $trackId) {
            $trackId = HikvisionIsapiMarker::trackId('1') ?? 101;
        }

        $at = $job->starts_at
            ? $job->starts_at->copy()->addSeconds((int) $job->pre_sec)
            : now();
        $start = $job->starts_at ?? $at;
        $end = $job->ends_at ?? $start->copy()->addSeconds(max(1, (int) $job->duration_sec));

        $tagPath = HikvisionIsapiMarker::tagPath($trackId, $s->webhook_path);
        $lockPath = HikvisionIsapiMarker::lockPath($trackId);

        return [
            'id' => (int) $job->id,
            'title' => (string) $job->title,
            'tag_name' => HikvisionIsapiMarker::tagName((string) $job->title),
            'event' => (string) ($job->event ?? ''),
            'channel' => $channel,
            'track_id' => $trackId,
            'time' => HikvisionIsapiMarker::formatTime($at),
            'starts_at' => HikvisionIsapiMarker::formatTime($start),
            'ends_at' => HikvisionIsapiMarker::formatTime($end),
            'requests' => [
                [
                    'id' => 'tag',
                    'method' => 'PUT',
                    'url' => HikvisionIsapiMarker::absoluteUrl($base, $tagPath),
                    'body' => HikvisionIsapiMarker::recordTagXml((string) $job->title, $at),
                    'required' => true,
                ],
                [
                    'id' => 'lock',
                    'method' => 'PUT',
                    'url' => HikvisionIsapiMarker::absoluteUrl($base, $lockPath),
                    'body' => HikvisionIsapiMarker::lockXml($start, $end),
                    'required' => false,
                ],
            ],
        ];
    }

    /**
     * Credentials for the LAN agent (once per pull).
     *
     * @return array{base_url:?string,login:?string,password:?string}
     */
    public function agentNvrAuth(?int $clubId = null): array
    {
        $s = $this->settings($clubId);

        return [
            'base_url' => $s->api_base_url,
            'login' => $s->api_login,
            'password' => $s->api_secret,
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

        return VideoSurveillanceMarkerJob::query()
            ->whereIn('id', $ids)
            ->whereIn('status', [
                VideoSurveillanceMarkerJob::STATUS_CLAIMED,
                VideoSurveillanceMarkerJob::STATUS_PENDING,
            ])
            ->update([
                'status' => VideoSurveillanceMarkerJob::STATUS_SENT,
                'sent_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  list<array{id:int|string, error?:string}>  $rows
     */
    public function markFailed(array $rows): int
    {
        $updated = 0;
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $error = mb_substr((string) ($row['error'] ?? 'marker failed'), 0, 500);
            $n = VideoSurveillanceMarkerJob::query()
                ->where('id', $id)
                ->update([
                    'status' => VideoSurveillanceMarkerJob::STATUS_FAILED,
                    'last_error' => $error,
                    'updated_at' => now(),
                ]);
            $updated += $n;
        }

        return $updated;
    }

    public function releaseStaleClaims(int $minutes = 2): int
    {
        return VideoSurveillanceMarkerJob::query()
            ->where('status', VideoSurveillanceMarkerJob::STATUS_CLAIMED)
            ->where('claimed_at', '<', now()->subMinutes(max(1, $minutes)))
            ->update([
                'status' => VideoSurveillanceMarkerJob::STATUS_PENDING,
                'claimed_at' => null,
                'updated_at' => now(),
            ]);
    }

    public function pendingCount(?int $clubId = null): int
    {
        $q = VideoSurveillanceMarkerJob::query()
            ->whereIn('status', [
                VideoSurveillanceMarkerJob::STATUS_PENDING,
                VideoSurveillanceMarkerJob::STATUS_CLAIMED,
            ]);
        if ($clubId) {
            $q->where('club_id', $clubId);
        }

        return $q->count();
    }

    private function sendWebhook(VideoSurveillanceSetting $s, array $body): bool
    {
        $base = rtrim((string) $s->api_base_url, '/');
        if ($base === '') {
            Log::info('VideoMarkerService: no api_base_url (dry-run OK for local test)', $body);

            return true;
        }

        $path = ltrim((string) ($s->webhook_path ?: '/markers'), '/');
        $url = $base.'/'.$path;
        $method = strtoupper((string) ($s->webhook_method ?: 'POST'));

        $req = Http::timeout(8)->acceptJson();
        if (filled($s->api_login) && filled($s->api_secret)) {
            $req = $req->withBasicAuth((string) $s->api_login, (string) $s->api_secret);
        } elseif (filled($s->api_secret)) {
            $req = $req->withToken((string) $s->api_secret);
        }

        $response = match ($method) {
            'PUT' => $req->put($url, $body),
            'PATCH' => $req->patch($url, $body),
            default => $req->post($url, $body),
        };

        if (! $response->successful()) {
            Log::warning('VideoMarkerService: webhook HTTP '.$response->status(), [
                'url' => $url,
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }
}
