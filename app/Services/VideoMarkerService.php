<?php

namespace App\Services;

use App\Models\VideoSurveillanceEvent;
use App\Models\VideoSurveillanceSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ставит метки на таймлайне видеосервера по событиям из админки.
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
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $start->copy()->addSeconds($duration)->toIso8601String(),
            'duration_sec' => $duration,
            'pre_sec' => $pre,
            'meta' => $payload['meta'] ?? [],
        ];

        try {
            return $this->sendWebhook($s, $body);
        } catch (Throwable $e) {
            Log::warning('VideoMarkerService: failed', [
                'error' => $e->getMessage(),
                'event' => $body['event'],
            ]);

            return false;
        }
    }

    private function sendWebhook(VideoSurveillanceSetting $s, array $body): bool
    {
        $base = rtrim((string) $s->api_base_url, '/');
        if ($base === '') {
            Log::info('VideoMarkerService: no api_base_url (dry-run OK for local test)', $body);

            // Без URL считаем «успехом сухого прогона», чтобы тест в админке не ломался на пустом API
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
