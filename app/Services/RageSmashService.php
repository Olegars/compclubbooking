<?php

namespace App\Services;

use App\Models\Computer;
use App\Models\Product;
use App\Models\VideoSurveillanceEvent;
use App\Models\VideoSurveillanceSetting;
use App\Services\LanLive\ShellGsiStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rage-Smash: удар по столу / key-mash + падение K/D.
 * Пишет hardware_abuse, ставит секундную метку на NVR, отдаёт напитки для оверлея шелла.
 */
class RageSmashService
{
    public const TRIGGER = 'hardware.abuse';

    public const WINDOW_MS = 60000;

    public const MARKER_DURATION_SEC = 1;

    public const MARKER_PRE_SEC = 1;

    public function __construct(
        private readonly VideoMarkerService $markers,
        private readonly ShellIncidentService $incidents,
        private readonly ShellGsiStore $gsi,
    ) {
    }

    /**
     * @param  array<string, mixed>  $snap
     */
    public function noteGsi(Computer $computer, array $snap): void
    {
        $event = strtolower((string) ($snap['event'] ?? ''));
        if (! in_array($event, ['kill', 'death'], true)) {
            return;
        }

        $now = (int) round(microtime(true) * 1000);
        $row = $this->tiltRow((int) $computer->id);
        $row['events'][] = ['t' => $now, 'e' => $event];
        $row['events'] = $this->trimEvents($row['events'], $now);
        $this->putTilt((int) $computer->id, $row);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     accepted: bool,
     *     reason: ?string,
     *     incident_id: ?int,
     *     created: bool,
     *     description: string,
     *     video_marked: bool,
     *     calm_down: array<string, mixed>
     * }
     */
    public function ingest(Computer $computer, string $severity, string $description, array $payload): array
    {
        $source = strtolower(trim((string) ($payload['source'] ?? '')));
        if (! in_array($source, ['imu', 'keymash', 'mouse_shock'], true)) {
            $source = 'keymash';
            $payload['source'] = $source;
        }

        if ($source !== 'imu' && ! $this->kdDropped($computer, $payload)) {
            return [
                'accepted' => false,
                'reason' => 'no_kd_drop',
                'incident_id' => null,
                'created' => false,
                'description' => $description,
                'video_marked' => false,
                'calm_down' => $this->calmDownPayload($computer, false),
            ];
        }

        $payload['kd_dropped'] = $this->kdDropped($computer, $payload);
        $payload['gsi'] = $this->gsi->get((int) $computer->id);

        $recorded = $this->incidents->record(
            $computer,
            ShellIncidentService::TYPE_HARDWARE_ABUSE,
            $description,
            $severity !== '' ? $severity : 'high',
            $payload,
        );

        $marked = false;
        try {
            $marked = $this->placeSmashMarker($computer, $payload, $source);
        } catch (Throwable $e) {
            Log::warning('RageSmash video marker failed: '.$e->getMessage(), [
                'computer_id' => $computer->id,
            ]);
        }

        return [
            'accepted' => true,
            'reason' => null,
            'incident_id' => $recorded['id'],
            'created' => $recorded['created'],
            'description' => $recorded['description'],
            'video_marked' => $marked,
            'calm_down' => $this->calmDownPayload($computer, true),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function kdDropped(Computer $computer, array $payload): bool
    {
        $deathsWindow = (int) ($payload['deaths_window'] ?? 0);
        $killsWindow = (int) ($payload['kills_window'] ?? 0);
        if ($deathsWindow >= 2 && $deathsWindow > $killsWindow) {
            return true;
        }

        $kdBefore = (float) ($payload['kd_before'] ?? -1);
        $kdAfter = (float) ($payload['kd_after'] ?? -1);
        if ($kdBefore > 0.01 && $kdAfter >= 0 && $kdAfter < $kdBefore * 0.7) {
            return true;
        }

        $row = $this->tiltRow((int) $computer->id);
        $now = (int) round(microtime(true) * 1000);
        $events = $this->trimEvents($row['events'] ?? [], $now);
        $deaths = 0;
        $kills = 0;
        foreach ($events as $ev) {
            if (($ev['e'] ?? '') === 'death') {
                $deaths++;
            } elseif (($ev['e'] ?? '') === 'kill') {
                $kills++;
            }
        }
        if ($deaths >= 2 && $deaths > $kills) {
            return true;
        }

        $live = $this->gsi->get((int) $computer->id);
        if (is_array($live) && strtolower((string) ($live['event'] ?? '')) === 'death') {
            return $deaths >= 1;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function calmDownPayload(Computer $computer, bool $triggered): array
    {
        return [
            'title' => 'Всё в порядке',
            'message' => $triggered
                ? 'Похоже, партия идёт тяжело. Стол тут ни при чём — сделайте паузу, можем принести напиток.'
                : 'Сделайте паузу, если нужно — в баре есть напитки.',
            'pc_name' => (string) $computer->name,
            'drinks' => $this->drinkOffers(),
        ];
    }

    /**
     * @return list<array{id:int,name:string,price:float,image:?string,category:?string}>
     */
    public function drinkOffers(int $limit = 3): array
    {
        return Product::query()
            ->where('is_active', true)
            ->where('stock', '>', 0)
            ->where(function ($q) {
                $q->whereNull('requires_marking')->orWhere('requires_marking', false);
            })
            ->where(function ($q) {
                $q->where('category', 'like', '%напит%')
                    ->orWhere('category', 'like', '%drink%')
                    ->orWhere('category', 'like', '%бар%')
                    ->orWhere('name', 'like', '%red bull%')
                    ->orWhere('name', 'like', '%энерг%')
                    ->orWhere('name', 'like', '%кола%')
                    ->orWhere('name', 'like', '%адреналин%')
                    ->orWhere('name', 'like', '%сок%')
                    ->orWhere('name', 'like', '%чай%');
            })
            ->orderBy('price')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get(['id', 'name', 'price', 'image', 'category'])
            ->map(fn (Product $p) => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'price' => (float) $p->price,
                'image' => $p->image ? (string) $p->image : null,
                'category' => $p->category ? (string) $p->category : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function placeSmashMarker(Computer $computer, array $payload, string $source): bool
    {
        $clubId = $computer->club_id ? (int) $computer->club_id : null;
        $title = 'Rage-Smash · '.($computer->name ?: ('PC#'.$computer->id));

        return $this->markers->placeMarkerForTrigger(self::TRIGGER, [
            'title' => $title,
            'duration_sec' => self::MARKER_DURATION_SEC,
            'pre_sec' => self::MARKER_PRE_SEC,
            'channel' => $payload['channel'] ?? null,
            'meta' => [
                'computer_id' => $computer->id,
                'source' => $source,
                'kind' => 'hardware_abuse',
            ],
        ], $clubId);
    }

    /**
     * Создаёт событие триггера в /admin/video-surveillance, если админ его ещё не завёл.
     * Иначе HID/SOS/rage-smash молча не ставят метку.
     */
    public static function ensureTriggerEvent(VideoSurveillanceSetting $s, string $triggerKey): void
    {
        if ($triggerKey === '' || $triggerKey === 'manual') {
            return;
        }
        if (! array_key_exists($triggerKey, VideoSurveillanceSetting::TRIGGERS)) {
            return;
        }

        $exists = VideoSurveillanceEvent::query()
            ->where('club_id', $s->club_id)
            ->where('trigger_key', $triggerKey)
            ->exists();
        if ($exists) {
            return;
        }

        $label = VideoSurveillanceSetting::TRIGGERS[$triggerKey];
        $code = str_replace('.', '_', $triggerKey);
        if (VideoSurveillanceEvent::query()->where('club_id', $s->club_id)->where('code', $code)->exists()) {
            $code = VideoSurveillanceEvent::makeCode($label);
        }

        $title = match ($triggerKey) {
            self::TRIGGER => 'Rage-Smash',
            'sos' => 'SOS',
            'store.assembly_start' => 'Сборка',
            'store.assembly_done' => 'ПК готов',
            default => 'HID',
        };

        VideoSurveillanceEvent::query()->create([
            'club_id' => $s->club_id,
            'code' => $code,
            'name' => $label,
            'description' => $triggerKey === self::TRIGGER
                ? 'Секундная закладка на NVR при ударе по столу / key-mash'
                : null,
            'is_enabled' => true,
            'trigger_key' => $triggerKey,
            'marker_title' => $title,
            'sort' => 80,
        ]);
    }

    /**
     * @return array{events: list<array{t:int,e:string}>}
     */
    private function tiltRow(int $computerId): array
    {
        $row = Cache::get($this->tiltKey($computerId));
        if (! is_array($row) || ! isset($row['events']) || ! is_array($row['events'])) {
            return ['events' => []];
        }

        return $row;
    }

    /**
     * @param  array{events: list<array{t:int,e:string}>}  $row
     */
    private function putTilt(int $computerId, array $row): void
    {
        Cache::put($this->tiltKey($computerId), $row, 180);
    }

    /**
     * @param  list<array{t?:int,e?:string}>  $events
     * @return list<array{t:int,e:string}>
     */
    private function trimEvents(array $events, int $nowMs): array
    {
        $cut = $nowMs - self::WINDOW_MS;
        $out = [];
        foreach ($events as $ev) {
            $t = (int) ($ev['t'] ?? 0);
            $e = (string) ($ev['e'] ?? '');
            if ($t >= $cut && in_array($e, ['kill', 'death'], true)) {
                $out[] = ['t' => $t, 'e' => $e];
            }
        }

        return $out;
    }

    private function tiltKey(int $computerId): string
    {
        return 'ragesmash:kd:'.$computerId;
    }
}
