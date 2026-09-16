<?php

namespace App\Services\LanLive;

use Illuminate\Support\Facades\Cache;

/**
 * Live GSI snapshots from club PCs (TTL ~30s). Used by bounty matching,
 * party energy (in-match lock), Ghost Coach (enemy economy / ultimates on LAN)
 * and Coach Whisper eco/drop sync for BookingGroup stacks.
 */
class ShellGsiStore
{
    public const TTL_SECONDS = 30;

    /**
     * @param  array<string, mixed>  $state
     */
    public function put(int $computerId, int $clubId, array $state): void
    {
        $state['computer_id'] = $computerId;
        $state['club_id'] = $clubId;
        $state['at'] = now()->toIso8601String();
        Cache::put($this->pcKey($computerId), $state, self::TTL_SECONDS);

        $ids = $this->clubIds($clubId);
        if (! in_array($computerId, $ids, true)) {
            $ids[] = $computerId;
            Cache::put($this->clubKey($clubId), array_values($ids), self::TTL_SECONDS * 4);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $computerId): ?array
    {
        $row = Cache::get($this->pcKey($computerId));

        return is_array($row) ? $row : null;
    }

    public function inMatch(int $computerId): bool
    {
        $row = $this->get($computerId);

        return (bool) ($row['in_match'] ?? false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function clubStates(int $clubId, int $exceptComputerId = 0): array
    {
        $out = [];
        foreach ($this->clubIds($clubId) as $id) {
            if ($exceptComputerId > 0 && $id === $exceptComputerId) {
                continue;
            }
            $row = $this->get($id);
            if ($row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    public function forget(int $computerId): void
    {
        Cache::forget($this->pcKey($computerId));
    }

    /**
     * @return list<int>
     */
    private function clubIds(int $clubId): array
    {
        $ids = Cache::get($this->clubKey($clubId), []);

        return array_values(array_unique(array_map('intval', is_array($ids) ? $ids : [])));
    }

    private function pcKey(int $computerId): string
    {
        return 'gsi:pc:'.$computerId;
    }

    private function clubKey(int $clubId): string
    {
        return 'gsi:club:'.$clubId;
    }
}
