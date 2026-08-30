<?php

namespace App\Services\Light;

use App\Models\Booking;
use App\Models\Computer;
use App\Models\DmxNode;
use App\Models\SpaceLight;
use App\Models\User;
use App\Services\Fan\FanControlService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LightControlService
{
    private int $nextFadeMs = 0;

    public function __construct(
        private FanControlService $fans,
    ) {}

    public function reconcileForComputer(int $computerId): ?SpaceLight
    {
        $computer = Computer::query()->find($computerId);
        if (! $computer || ! $this->fans->ensureSpaceForComputer($computer)) {
            return null;
        }

        return $this->reconcileForSpace((int) $computer->space_id, (int) $computer->club_id);
    }

    public function reconcileForSpace(int $spaceId, ?int $clubId = null): ?SpaceLight
    {
        return DB::transaction(function () use ($spaceId, $clubId) {
            $light = SpaceLight::query()
                ->where('space_id', $spaceId)
                ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
                ->lockForUpdate()
                ->first();

            if (! $light) {
                return null;
            }

            $this->applyPowerPolicy($light);
            $light->save();

            return $light;
        });
    }

    /**
     * After PIN/QR login: fade from lobby white to this player's last color
     * (green on first visit).
     *
     * @return array{light: ?SpaceLight, first_visit: bool}
     */
    public function applyLoginScene(int $computerId, User $user, ?int $bookingId = null): array
    {
        $computer = Computer::query()->find($computerId);
        if (! $computer || ! $this->fans->ensureSpaceForComputer($computer)) {
            return ['light' => null, 'first_visit' => false];
        }

        $first = $this->isFirstVisit((int) $user->id, $bookingId);
        $saved = $user->lightScene();
        $useGreen = $first || $saved === null;

        $color = $useGreen ? 'green' : $saved['color'];
        $brightness = $useGreen
            ? SpaceLight::normalizeBrightness((int) config('light.default_brightness', 80))
            : $saved['brightness'];
        $effect = $useGreen ? SpaceLight::EFFECT_NONE : $saved['effect'];
        if ($brightness <= 0) {
            $brightness = SpaceLight::normalizeBrightness((int) config('light.default_brightness', 80));
        }

        $light = DB::transaction(function () use ($computer, $color, $brightness, $effect) {
            $light = SpaceLight::query()
                ->where('space_id', $computer->space_id)
                ->where('club_id', $computer->club_id)
                ->lockForUpdate()
                ->first();
            if (! $light) {
                return null;
            }

            $light->desired_color = $color;
            $light->desired_effect = $effect;
            $light->desired_brightness = $brightness;
            $light->vacant = false;
            $light->last_on_color = $color;
            $light->last_on_brightness = $brightness;
            $light->last_on_effect = $effect;
            $light->save();

            return $light->fresh();
        });

        if ($useGreen) {
            $user->saveLightScene('green', $brightness, SpaceLight::EFFECT_NONE);
        }

        $this->nextFadeMs = max(0, (int) config('light.fade_login_ms', 2500));

        return ['light' => $light, 'first_visit' => $first];
    }

    /**
     * @return array{light: ?SpaceLight, locked: bool, remaining_sec: int}
     */
    public function setSceneForComputer(
        int $computerId,
        ?string $color = null,
        ?int $brightness = null,
        ?string $effect = null,
    ): array {
        $computer = Computer::query()->find($computerId);
        if (! $computer || ! $this->fans->ensureSpaceForComputer($computer)) {
            return ['light' => null, 'locked' => false, 'remaining_sec' => 0];
        }

        $result = DB::transaction(function () use ($computer, $color, $brightness, $effect) {
            $light = SpaceLight::query()
                ->where('space_id', $computer->space_id)
                ->where('club_id', $computer->club_id)
                ->lockForUpdate()
                ->first();

            if (! $light) {
                return ['light' => null, 'locked' => false, 'remaining_sec' => 0];
            }

            $cooldown = max(0, (int) config('light.manual_cooldown_sec', 2));
            $remaining = $this->manualLockRemainingSec($light, $cooldown);

            $nextColor = $color !== null
                ? SpaceLight::normalizeColor($color)
                : SpaceLight::normalizeColor((string) $light->desired_color);
            $nextEffect = SpaceLight::normalizeEffect(
                $effect ?? (string) $light->desired_effect,
                $nextColor,
            );
            if ($nextColor === SpaceLight::EFFECT_RAINBOW) {
                $nextEffect = SpaceLight::EFFECT_RAINBOW;
                $nextColor = SpaceLight::EFFECT_RAINBOW;
            } elseif ($nextEffect === SpaceLight::EFFECT_RAINBOW) {
                $nextColor = SpaceLight::EFFECT_RAINBOW;
            }
            $nextBrightness = $brightness !== null
                ? SpaceLight::normalizeBrightness($brightness)
                : SpaceLight::normalizeBrightness((int) $light->desired_brightness);

            $unchanged = $nextColor === SpaceLight::normalizeColor((string) $light->desired_color)
                && $nextEffect === SpaceLight::normalizeEffect((string) $light->desired_effect, (string) $light->desired_color)
                && $nextBrightness === SpaceLight::normalizeBrightness((int) $light->desired_brightness);

            if ($remaining > 0 && ! $unchanged) {
                $this->applyPowerPolicy($light);
                $light->save();

                return ['light' => $light->fresh(), 'locked' => true, 'remaining_sec' => $remaining];
            }

            $light->desired_color = $nextColor;
            $light->desired_effect = $nextEffect;
            $light->desired_brightness = $nextBrightness;
            $light->vacant = false;
            if ($nextBrightness > 0) {
                $light->last_on_color = $nextColor;
                $light->last_on_brightness = $nextBrightness;
                $light->last_on_effect = $nextEffect;
            }
            $light->last_manual_at = now();
            $light->last_manual_by_computer_id = (int) $computer->id;
            $light->save();

            return ['light' => $light->fresh(), 'locked' => false, 'remaining_sec' => 0];
        });

        if (! ($result['locked'] ?? true) && $result['light']) {
            $this->nextFadeMs = max(0, (int) config('light.fade_manual_ms', 300));
            $this->persistSceneForActiveUser($computer, $result['light']);
        }

        return $result;
    }

    /**
     * @return array{light: ?SpaceLight, locked: bool, remaining_sec: int}
     */
    public function acknowledgeApplied(
        int $computerId,
        string $appliedColor,
        int $appliedBrightness,
        string $appliedEffect = SpaceLight::EFFECT_NONE,
        ?string $lastError = null,
    ): array {
        $computer = Computer::query()->find($computerId);
        if (! $computer || ! $this->fans->ensureSpaceForComputer($computer)) {
            return ['light' => null, 'locked' => false, 'remaining_sec' => 0];
        }

        return DB::transaction(function () use ($computer, $appliedColor, $appliedBrightness, $appliedEffect, $lastError) {
            $light = SpaceLight::query()
                ->where('space_id', $computer->space_id)
                ->where('club_id', $computer->club_id)
                ->lockForUpdate()
                ->first();

            if (! $light) {
                return ['light' => null, 'locked' => false, 'remaining_sec' => 0];
            }

            $autoCooldown = max(0, (int) config('light.auto_apply_cooldown_sec', 5));
            $remaining = $this->autoLockRemainingSec($light, $autoCooldown);
            $samePc = (int) $light->last_applied_by_computer_id === (int) $computer->id;
            if ($remaining > 0 && ! $samePc) {
                return ['light' => $light, 'locked' => true, 'remaining_sec' => $remaining];
            }

            $light->applied_color = SpaceLight::normalizeColor($appliedColor);
            $light->applied_brightness = SpaceLight::normalizeBrightness($appliedBrightness);
            $light->applied_effect = SpaceLight::normalizeEffect($appliedEffect, $appliedColor);
            $light->last_error = $lastError ? mb_substr($lastError, 0, 2000) : null;
            $light->last_applied_at = now();
            $light->last_applied_by_computer_id = (int) $computer->id;
            $light->save();

            return ['light' => $light->fresh(), 'locked' => false, 'remaining_sec' => 0];
        });
    }

    public function stateForComputer(int $computerId): array
    {
        $computer = Computer::query()->find($computerId);
        if (! $computer || ! $this->fans->ensureSpaceForComputer($computer)) {
            return ['available' => false];
        }

        $this->reconcileForSpace((int) $computer->space_id, (int) $computer->club_id);

        $light = SpaceLight::query()
            ->with('dmxNode')
            ->where('space_id', $computer->space_id)
            ->where('club_id', $computer->club_id)
            ->first();

        if (! $light) {
            return ['available' => false];
        }

        return $this->statePayload($light, (int) $computer->id);
    }

    public function statePayload(SpaceLight $light, ?int $forComputerId = null): array
    {
        $light->loadMissing('dmxNode');
        $node = $light->dmxNode;
        $occupied = $this->spaceHasActiveSession($light);
        $allOff = $this->spaceAllComputersOffline($light);
        $manualCooldown = max(0, (int) config('light.manual_cooldown_sec', 2));
        $autoCooldown = max(0, (int) config('light.auto_apply_cooldown_sec', 5));
        $manualRemaining = $this->manualLockRemainingSec($light, $manualCooldown);
        $autoRemaining = $this->autoLockRemainingSec($light, $autoCooldown);

        $available = $node && $node->is_active && (int) $node->club_id === (int) $light->club_id;

        $color = SpaceLight::normalizeColor((string) $light->desired_color);
        $effect = SpaceLight::normalizeEffect((string) $light->desired_effect, $color);
        $brightness = SpaceLight::normalizeBrightness((int) $light->desired_brightness);
        [$r, $g, $b] = SpaceLight::rgbForColor($color);

        $nodes = [];
        if ($available) {
            $nodes[] = $this->nodeUniversePayload($node);
        }

        $fadeMs = $this->nextFadeMs;
        $this->nextFadeMs = 0;

        return [
            'available' => $available,
            'light_id' => (int) $light->id,
            'club_id' => (int) $light->club_id,
            'space_id' => (int) $light->space_id,
            'color' => $color,
            'brightness' => $brightness,
            'effect' => $effect,
            'fade_ms' => $fadeMs,
            'applied_color' => SpaceLight::normalizeColor((string) $light->applied_color),
            'applied_brightness' => SpaceLight::normalizeBrightness((int) $light->applied_brightness),
            'applied_effect' => SpaceLight::normalizeEffect((string) $light->applied_effect, (string) $light->applied_color),
            'rgb' => ['r' => $r, 'g' => $g, 'b' => $b],
            'rainbow_period_ms' => max(1000, (int) config('light.rainbow_period_ms', 8000)),
            'layout' => SpaceLight::normalizeLayout((string) $light->layout),
            'start_channel' => (int) $light->start_channel,
            'fixture_count' => max(1, (int) $light->fixture_count),
            'node' => $available ? [
                'host' => (string) $node->host,
                'port' => (int) ($node->port ?: config('light.artnet_port', 6454)),
                'universe' => (int) $node->universe,
            ] : null,
            'nodes' => $nodes,
            'facts' => [
                'session' => $occupied,
                'sessions_in_space' => $this->spaceActiveSessionCount($light),
                'all_pcs_off' => $allOff,
            ],
            'manual_lock' => [
                'locked' => $manualRemaining > 0,
                'remaining_sec' => $manualRemaining,
            ],
            'auto_lock' => [
                'locked' => $autoRemaining > 0,
                'remaining_sec' => $autoRemaining,
            ],
            'last_error' => $light->last_error,
            'for_computer_id' => $forComputerId,
        ];
    }

    /**
     * @return array{host:string,port:int,universe:int,fixtures:list<array<string,mixed>>}
     */
    private function nodeUniversePayload(DmxNode $node): array
    {
        $siblings = SpaceLight::query()
            ->where('dmx_node_id', $node->id)
            ->orderBy('start_channel')
            ->get();

        $fixtures = [];
        foreach ($siblings as $row) {
            $color = SpaceLight::normalizeColor((string) $row->desired_color);
            $effect = SpaceLight::normalizeEffect((string) $row->desired_effect, $color);
            $brightness = SpaceLight::normalizeBrightness((int) $row->desired_brightness);
            [$r, $g, $b] = SpaceLight::rgbForColor($color);
            $fixtures[] = [
                'light_id' => (int) $row->id,
                'start' => (int) $row->start_channel,
                'count' => max(1, (int) $row->fixture_count),
                'layout' => SpaceLight::normalizeLayout((string) $row->layout),
                'color' => $color,
                'brightness' => $brightness,
                'effect' => $effect,
                'r' => $r,
                'g' => $g,
                'b' => $b,
            ];
        }

        return [
            'host' => (string) $node->host,
            'port' => (int) ($node->port ?: config('light.artnet_port', 6454)),
            'universe' => (int) $node->universe,
            'fixtures' => $fixtures,
        ];
    }

    public function assertChannelsFree(int $nodeId, int $start, int $end, ?int $exceptLightId = null): void
    {
        $q = SpaceLight::query()->where('dmx_node_id', $nodeId);
        if ($exceptLightId) {
            $q->where('id', '!=', $exceptLightId);
        }
        foreach ($q->with('space:id,name')->get() as $row) {
            if ($row->channelSpanOverlaps($start, $end)) {
                throw new InvalidArgumentException(
                    'Каналы DMX пересекаются с комнатой «'.($row->space?->name ?: '#'.$row->space_id).'»'
                );
            }
        }
        if ($end > 512 || $start < 1) {
            throw new InvalidArgumentException('DMX каналы должны быть в диапазоне 1–512');
        }
    }

    /**
     * Off only when every PC in the room is powered off (same heartbeat/state
     * as ventilation orphan). Any PC on/booting → lobby white if no session.
     */
    private function applyPowerPolicy(SpaceLight $light): void
    {
        $defaultBr = SpaceLight::normalizeBrightness((int) config('light.default_brightness', 80));
        $allOff = $this->spaceAllComputersOffline($light);

        if ($allOff) {
            if ((int) $light->desired_brightness !== 0) {
                $this->nextFadeMs = max($this->nextFadeMs, (int) config('light.fade_off_ms', 800));
            }
            $light->desired_brightness = 0;
            $light->vacant = true;

            return;
        }

        $wasOff = (bool) $light->vacant || (int) $light->desired_brightness <= 0;
        $light->vacant = false;

        if ($this->spaceHasActiveSession($light)) {
            if ($wasOff) {
                $light->desired_color = SpaceLight::normalizeColor((string) ($light->last_on_color ?: 'white'));
                $light->desired_effect = SpaceLight::normalizeEffect(
                    (string) ($light->last_on_effect ?: SpaceLight::EFFECT_NONE),
                    (string) $light->desired_color,
                );
                $restore = SpaceLight::normalizeBrightness((int) $light->last_on_brightness);
                $light->desired_brightness = $restore > 0 ? $restore : $defaultBr;
                $this->nextFadeMs = max($this->nextFadeMs, (int) config('light.fade_idle_ms', 1200));
            }

            return;
        }

        $idleColor = 'white';
        $idleEffect = SpaceLight::EFFECT_NONE;
        $changed = SpaceLight::normalizeColor((string) $light->desired_color) !== $idleColor
            || SpaceLight::normalizeEffect((string) $light->desired_effect) !== $idleEffect
            || SpaceLight::normalizeBrightness((int) $light->desired_brightness) !== $defaultBr;

        $light->desired_color = $idleColor;
        $light->desired_effect = $idleEffect;
        $light->desired_brightness = $defaultBr;

        if ($changed || $wasOff) {
            $this->nextFadeMs = max($this->nextFadeMs, (int) config('light.fade_idle_ms', 1200));
        }
    }

    private function persistSceneForActiveUser(Computer $computer, SpaceLight $light): void
    {
        $booking = Booking::query()
            ->where('computer_id', $computer->id)
            ->where('status', 'active')
            ->orderByDesc('id')
            ->first();
        if (! $booking?->user_id) {
            return;
        }
        $user = User::query()->find((int) $booking->user_id);
        if (! $user) {
            return;
        }
        $user->saveLightScene(
            (string) $light->desired_color,
            (int) $light->desired_brightness,
            (string) $light->desired_effect,
        );
    }

    private function isFirstVisit(int $userId, ?int $currentBookingId): bool
    {
        $prior = Booking::query()
            ->where('user_id', $userId)
            ->when($currentBookingId, fn ($q) => $q->where('id', '!=', $currentBookingId))
            ->whereNotNull('actual_started_at')
            ->exists();

        if ($prior) {
            return false;
        }

        return ! Booking::query()
            ->where('user_id', $userId)
            ->when($currentBookingId, fn ($q) => $q->where('id', '!=', $currentBookingId))
            ->where('status', 'completed')
            ->exists();
    }

    private function spaceAllComputersOffline(SpaceLight $light): bool
    {
        $computers = Computer::query()
            ->where('space_id', $light->space_id)
            ->where('club_id', $light->club_id)
            ->get(['id', 'power_state', 'last_seen_at']);

        if ($computers->isEmpty()) {
            return true;
        }

        $staleSec = max(30, (int) config('club.power.heartbeat_stale_seconds', 180));
        $cutoff = now()->subSeconds($staleSec);

        foreach ($computers as $pc) {
            $state = (string) ($pc->power_state ?? 'off');
            if (in_array($state, ['on', 'booting'], true)) {
                return false;
            }
            if ($pc->last_seen_at && $pc->last_seen_at->gte($cutoff)) {
                return false;
            }
        }

        return true;
    }

    private function manualLockRemainingSec(SpaceLight $light, int $cooldown): int
    {
        if ($cooldown <= 0 || ! $light->last_manual_at) {
            return 0;
        }

        return max(0, $cooldown - $this->secondsSince($light->last_manual_at));
    }

    private function autoLockRemainingSec(SpaceLight $light, int $cooldown): int
    {
        if ($cooldown <= 0 || ! $light->last_applied_at) {
            return 0;
        }

        return max(0, $cooldown - $this->secondsSince($light->last_applied_at));
    }

    private function secondsSince(\DateTimeInterface $at): int
    {
        return max(0, now()->getTimestamp() - $at->getTimestamp());
    }

    private function spaceHasActiveSession(SpaceLight $light): bool
    {
        return $this->spaceActiveSessionCount($light) > 0;
    }

    private function spaceActiveSessionCount(SpaceLight $light): int
    {
        $computerIds = Computer::query()
            ->where('space_id', $light->space_id)
            ->where('club_id', $light->club_id)
            ->pluck('id');

        if ($computerIds->isEmpty()) {
            return 0;
        }

        return (int) Booking::query()
            ->where('status', 'active')
            ->whereIn('computer_id', $computerIds)
            ->count();
    }
}
