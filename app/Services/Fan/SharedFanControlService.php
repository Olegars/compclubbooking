<?php

namespace App\Services\Fan;

use App\Models\SharedFan;
use App\Models\SharedFanLink;
use App\Models\SharedFanMap;
use App\Models\SpaceFan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SharedFanControlService
{
    /**
     * After personal desired changes: refresh supply pool for club + linked exhausts.
     *
     * @param  list<int>|null  $spaceFanIds  when set, also refresh exhausts linked to these
     */
    public function recomputeAfterPersonalChange(int $clubId, ?array $spaceFanIds = null): void
    {
        $this->recomputeSupplyPool($clubId);

        if ($spaceFanIds === null) {
            $this->recomputeAllExhaust($clubId);

            return;
        }

        $ids = array_values(array_unique(array_map('intval', $spaceFanIds)));
        if ($ids === []) {
            return;
        }

        $sharedIds = SharedFanLink::query()
            ->whereIn('space_fan_id', $ids)
            ->pluck('shared_fan_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        foreach ($sharedIds as $sharedId) {
            $this->recomputeSharedFan($sharedId);
        }
    }

    public function recomputeSupplyPool(int $clubId): void
    {
        $loadPct = $this->loadPctFromSpaceFans(
            SpaceFan::query()->where('club_id', $clubId)->get(['desired_power'])
        );

        $supplies = SharedFan::query()
            ->where('club_id', $clubId)
            ->where('kind', SharedFan::KIND_SUPPLY)
            ->get();

        foreach ($supplies as $fan) {
            $this->applyLoad($fan, $loadPct);
        }
    }

    public function recomputeAllExhaust(int $clubId): void
    {
        $ids = SharedFan::query()
            ->where('club_id', $clubId)
            ->where('kind', SharedFan::KIND_EXHAUST)
            ->pluck('id');

        foreach ($ids as $id) {
            $this->recomputeSharedFan((int) $id);
        }
    }

    public function recomputeSharedFan(int $sharedFanId): void
    {
        $fan = SharedFan::query()->with('spaceFans:id,desired_power')->find($sharedFanId);
        if (! $fan) {
            return;
        }

        if ($fan->isSupply()) {
            $this->recomputeSupplyPool((int) $fan->club_id);

            return;
        }

        $loadPct = $this->loadPctFromSpaceFans($fan->spaceFans);
        $this->applyLoad($fan, $loadPct);
    }

    /**
     * @param  Collection<int, SpaceFan>|iterable<SpaceFan>  $fans
     */
    public function loadPctFromSpaceFans(iterable $fans): int
    {
        $percents = [];
        foreach ($fans as $fan) {
            $percents[] = SpaceFan::speedToPercent((int) $fan->desired_power);
        }

        return $this->roundLoadPct($percents);
    }

    /**
     * @param  list<int>  $percents
     */
    public function roundLoadPct(array $percents): int
    {
        if ($percents === []) {
            return 50;
        }

        $avg = array_sum($percents) / count($percents);
        $rounded = (int) (round($avg / 10) * 10);

        return max(50, min(100, $rounded));
    }

    public function applyLoad(SharedFan $fan, int $loadPct): void
    {
        $loadPct = max(50, min(100, (int) (round($loadPct / 10) * 10)));
        $output = $this->mapOutput($fan, $loadPct);
        $desired = SpaceFan::sharedOutputToSpeed($output);

        if ((int) $fan->desired_power === $desired) {
            return;
        }

        $fan->desired_power = $desired;
        $fan->save();
    }

    public function mapOutput(SharedFan $fan, int $loadPct): int
    {
        $row = SharedFanMap::query()
            ->where('shared_fan_id', $fan->id)
            ->where('load_pct', $loadPct)
            ->first();

        $output = (int) ($row?->output_pct ?? 50);

        return $output >= 100 ? 100 : 50;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function targetsPayload(?int $clubId = null): array
    {
        $fans = SharedFan::query()
            ->with(['relayBoard:id,host,port,is_active,club_id', 'maps'])
            ->when($clubId, fn ($q) => $q->where('club_id', $clubId))
            ->whereHas('relayBoard', fn ($q) => $q->where('is_active', true))
            ->orderBy('id')
            ->get();

        $payload = [];
        foreach ($fans as $fan) {
            $board = $fan->relayBoard;
            if (! $board) {
                continue;
            }

            $loadPct = $fan->isSupply()
                ? $this->loadPctFromSpaceFans(
                    SpaceFan::query()->where('club_id', $fan->club_id)->get(['desired_power'])
                )
                : $this->loadPctFromSpaceFans(
                    $fan->spaceFans()->get(['space_fans.id', 'space_fans.desired_power'])
                );

            [$k1, $k2] = SpaceFan::speedToRelays((int) $fan->desired_power);

            $payload[] = [
                'id' => (int) $fan->id,
                'club_id' => (int) $fan->club_id,
                'kind' => (string) $fan->kind,
                'name' => (string) $fan->name,
                'host' => (string) $board->host,
                'port' => (int) ($board->port ?: config('fan.w5100_default_port', 30000)),
                'channel' => (int) $fan->channel,
                'channel2' => (int) $fan->channel2,
                'desired_power' => (int) $fan->desired_power,
                'applied_power' => (int) $fan->applied_power,
                'load_pct' => $loadPct,
                'k1' => $k1,
                'k2' => $k2,
                'needs_apply' => (int) $fan->desired_power !== (int) $fan->applied_power,
            ];
        }

        return $payload;
    }

    /**
     * @param  list<array{id:int,applied_power:int,last_error?:string|null}>  $items
     * @return int updated count
     */
    public function acknowledgeApplied(array $items): int
    {
        $updated = 0;

        DB::transaction(function () use ($items, &$updated) {
            foreach ($items as $item) {
                $id = (int) ($item['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $fan = SharedFan::query()->lockForUpdate()->find($id);
                if (! $fan) {
                    continue;
                }
                $fan->applied_power = SpaceFan::normalizeSpeed((int) ($item['applied_power'] ?? 1));
                // Shared only night/high — clamp mid to high for ack safety
                if ((int) $fan->applied_power === SpaceFan::SPEED_MID) {
                    $fan->applied_power = SpaceFan::SPEED_HIGH;
                }
                $fan->last_error = isset($item['last_error']) ? (string) $item['last_error'] : null;
                $fan->last_applied_at = now();
                $fan->save();
                $updated++;
            }
        });

        return $updated;
    }
}
