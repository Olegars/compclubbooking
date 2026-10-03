<?php

namespace App\Services\Light;

use App\Models\Booking;
use App\Models\Computer;
use App\Models\Order;
use App\Models\WledController;
use App\Models\WledCue;
use App\Support\OrderDeliveryTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WledCueService
{
    public function fire(int $clubId, string $eventId, bool $coalesce = true): int
    {
        if ($clubId < 1 || ! WledCorridorCatalog::has($eventId)) {
            return 0;
        }

        $controllers = WledController::query()
            ->where('club_id', $clubId)
            ->where('is_active', true)
            ->get();

        $made = 0;
        foreach ($controllers as $controller) {
            $binding = $controller->binding($eventId);
            if (! $binding || empty($binding['enabled'])) {
                continue;
            }
            if ($coalesce && $this->hasOpenCue((int) $controller->id, $eventId)) {
                continue;
            }
            $this->enqueue($controller, $eventId, $binding, WledCorridorCatalog::priority($eventId));
            $made++;
        }

        return $made;
    }

    public function flashTest(WledController $controller): WledCue
    {
        $binding = [
            'enabled' => true,
            'color' => 'white',
            'brightness' => 100,
            'effect' => WledCorridorCatalog::EFFECT_BLINK,
            'fx' => 1,
            'sx' => 160,
            'duration_sec' => 3,
            'fade_sec' => 0,
            'channels' => [1, 2, 3, 4],
        ];

        return $this->enqueue($controller, 'test', $binding, 70);
    }

    public function fireForOrder(Order $order): void
    {
        $clubId = $this->clubIdForOrder($order);
        if ($clubId < 1) {
            return;
        }
        $this->fire($clubId, 'bar.order');
    }

    public function fireForBooking(Booking $booking): void
    {
        $computerId = OrderDeliveryTarget::computerIdFromBooking($booking);
        if (! $computerId) {
            return;
        }
        $clubId = (int) Computer::query()->whereKey($computerId)->value('club_id');
        $this->fire($clubId, 'booking.new');
    }

    public function clubIdForOrder(Order $order): int
    {
        if ($order->booking_id) {
            $booking = $order->relationLoaded('booking')
                ? $order->booking
                : Booking::query()->find($order->booking_id);
            if ($booking) {
                $computerId = OrderDeliveryTarget::computerIdFromBooking($booking);
                $clubId = $computerId
                    ? (int) Computer::query()->whereKey($computerId)->value('club_id')
                    : 0;
                if ($clubId > 0) {
                    return $clubId;
                }
            }
        }

        $name = trim((string) $order->pc_name);
        if ($name !== '') {
            $computer = Computer::query()->where('name', $name)->first();
            if (! $computer && preg_match('/^ПК\s*№\s*(\d+)$/u', $name, $m)) {
                $computer = Computer::query()->find((int) $m[1]);
            }
            if ($computer && (int) $computer->club_id > 0) {
                return (int) $computer->club_id;
            }
        }

        $clubs = WledController::query()
            ->where('is_active', true)
            ->distinct()
            ->pluck('club_id');

        return $clubs->count() === 1 ? (int) $clubs->first() : 0;
    }

    /**
     * One shell claims at most one open cue per controller.
     *
     * @return list<array<string, mixed>>
     */
    public function claim(Computer $computer): array
    {
        $clubId = (int) $computer->club_id;
        if ($clubId < 1) {
            return [];
        }

        return DB::transaction(function () use ($computer, $clubId) {
            WledCue::query()
                ->where('club_id', $clubId)
                ->whereNull('played_at')
                ->whereNotNull('claimed_at')
                ->where('claimed_at', '<', now()->subSeconds(20))
                ->update([
                    'claimed_at' => null,
                    'claimed_by_computer_id' => null,
                ]);

            $cues = WledCue::query()
                ->where('club_id', $clubId)
                ->whereNull('played_at')
                ->whereNull('claimed_at')
                ->where('expires_at', '>', now())
                ->orderByDesc('priority')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $picked = [];
            foreach ($cues as $cue) {
                $controllerId = (int) $cue->wled_controller_id;
                if (isset($picked[$controllerId])) {
                    continue;
                }
                $picked[$controllerId] = $cue;
            }

            $out = [];
            foreach ($picked as $cue) {
                $controller = WledController::query()->find($cue->wled_controller_id);
                if (! $controller) {
                    $cue->played_at = now();
                    $cue->last_error = 'controller missing';
                    $cue->save();
                    continue;
                }

                $cue->claimed_at = now();
                $cue->claimed_by_computer_id = (int) $computer->id;
                $cue->save();

                $payload = is_array($cue->payload) ? $cue->payload : [];
                $out[] = [
                    'id' => (int) $cue->id,
                    'controller_id' => (int) $controller->id,
                    'event_id' => (string) $cue->event_id,
                    'priority' => (int) $cue->priority,
                    'host' => (string) $controller->host,
                    'port' => (int) $controller->http_port,
                    'duration_ms' => (int) ($payload['duration_ms'] ?? 0),
                    'play' => is_array($payload['play'] ?? null) ? $payload['play'] : [],
                    'idle' => is_array($payload['idle'] ?? null) ? $payload['idle'] : null,
                ];
            }

            return $out;
        });
    }

    /**
     * Controllers whose effect list the admin asked to read off the device.
     *
     * @return list<array{id:int,host:string,port:int}>
     */
    public function claimEffectSync(Computer $computer): array
    {
        $clubId = (int) $computer->club_id;
        if ($clubId < 1) {
            return [];
        }

        return DB::transaction(function () use ($clubId) {
            $rows = WledController::query()
                ->where('club_id', $clubId)
                ->whereNotNull('effects_sync_requested_at')
                ->where(function ($q) {
                    $q->whereNull('effects_sync_claimed_at')
                        ->orWhere('effects_sync_claimed_at', '<', now()->subSeconds(20));
                })
                ->lockForUpdate()
                ->get();

            $out = [];
            foreach ($rows as $row) {
                $row->effects_sync_claimed_at = now();
                $row->save();
                $out[] = [
                    'id' => (int) $row->id,
                    'host' => (string) $row->host,
                    'port' => (int) $row->http_port,
                ];
            }

            return $out;
        });
    }

    /**
     * @param  list<mixed>  $names
     */
    public function storeEffects(WledController $controller, Computer $computer, bool $ok, array $names, ?string $error): bool
    {
        if ((int) $computer->club_id !== (int) $controller->club_id) {
            return false;
        }

        $controller->effects_sync_requested_at = null;
        $controller->effects_sync_claimed_at = null;
        if ($ok) {
            $clean = WledCorridorCatalog::sanitizeEffectNames($names);
            if ($clean === []) {
                $controller->effects_error = 'пустой список эффектов';
            } else {
                $controller->effects = $clean;
                $controller->effects_synced_at = now();
                $controller->effects_error = null;
            }
        } else {
            $controller->effects_error = mb_substr(trim((string) $error) ?: 'не удалось прочитать эффекты', 0, 500);
        }
        $controller->save();

        return true;
    }

    public function acknowledge(WledCue $cue, Computer $computer, bool $ok, ?string $error): bool
    {
        if ((int) $computer->club_id !== (int) $cue->club_id) {
            return false;
        }
        if ($cue->claimed_by_computer_id && (int) $cue->claimed_by_computer_id !== (int) $computer->id) {
            return false;
        }

        $cue->played_at = now();
        $cue->last_error = $ok ? null : mb_substr(trim((string) $error) ?: 'wled', 0, 500);
        $cue->save();

        $controller = $cue->controller;
        if ($controller) {
            $controller->last_played_at = now();
            $controller->last_error = $cue->last_error;
            $controller->save();
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $binding
     */
    private function enqueue(WledController $controller, string $eventId, array $binding, int $priority): WledCue
    {
        $durationSec = (float) ($binding['duration_sec'] ?? 0);
        $durationMs = (int) round(max(0, $durationSec) * 1000);
        $ttl = max(45, (int) ceil($durationSec) + 30);

        return WledCue::query()->create([
            'club_id' => (int) $controller->club_id,
            'wled_controller_id' => (int) $controller->id,
            'event_id' => $eventId,
            'priority' => max(0, min(255, $priority)),
            'payload' => [
                'duration_ms' => $durationMs,
                'play' => WledCorridorCatalog::playState($binding),
                'idle' => $durationMs > 0
                    ? WledCorridorCatalog::idleState(
                        (bool) $controller->idle_on,
                        (string) $controller->idle_color,
                        (int) $controller->idle_brightness,
                    )
                    : null,
            ],
            'expires_at' => now()->addSeconds($ttl),
        ]);
    }

    private function hasOpenCue(int $controllerId, string $eventId): bool
    {
        return WledCue::query()
            ->where('wled_controller_id', $controllerId)
            ->where('event_id', $eventId)
            ->whereNull('played_at')
            ->whereNull('claimed_at')
            ->where('expires_at', '>', now())
            ->exists();
    }

    public static function reportFailure(\Throwable $e, string $where): void
    {
        Log::warning('WLED corridor '.$where.': '.$e->getMessage());
    }
}
