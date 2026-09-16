<?php

namespace App\Services\LanLive;

use App\Models\Booking;
use App\Models\Computer;
use Illuminate\Support\Facades\Cache;

/**
 * Ghost Coach extension for BookingGroup stacks: one CS2 freeze-time whisper
 * for the whole party — eco if the shared bank is poor, otherwise an AWP drop.
 */
class PartyEcoDropSynchronizer
{
    public const ECO_BANK_PER_PLAYER = 2000;

    public const AWP_COST = 4750;

    public const DROP_DONOR_MIN = 5500;

    public const LINE_TTL_SECONDS = 18;

    public const ECO_LINE = 'Эко-раунд, копим на бай';

    public function __construct(
        private readonly ShellGsiStore $gsi,
    ) {
    }

    public function isActiveParty(?Booking $booking): bool
    {
        return count($this->partyComputerIds($booking)) >= 2;
    }

    public function isCs2Freeze(array $snap): bool
    {
        if (($snap['game'] ?? 'cs2') === 'dota') {
            return false;
        }
        $event = strtolower((string) ($snap['event'] ?? ''));
        $phase = strtolower((string) ($snap['phase'] ?? ''));

        return $event === 'freezetime' || $phase === 'freezetime';
    }

    /**
     * Same line for every party PC in this freeze / round (cache + heard-once).
     *
     * @param  array<string, mixed>  $snap
     */
    public function maybeAnnounce(Computer $computer, Booking $booking, array $snap): ?string
    {
        $features = app(\App\Services\ClubFeatureService::class);
        $clubId = $features->clubIdForComputer($computer);
        if (! $features->enabled($clubId, 'coach_whisper')) {
            return null;
        }

        $groupId = (int) ($booking->booking_group_id ?? 0);
        if ($groupId < 1 || ! $this->isCs2Freeze($snap)) {
            return null;
        }

        $round = (int) ($snap['round'] ?? 0);
        $match = (string) ($snap['match_id'] ?? '');
        $cacheKey = 'coach:party:'.$groupId.':'.$match.':'.$round;
        $heardKey = 'coach:party:heard:'.$groupId.':'.$match.':'.$round.':'.$computer->id;

        $cached = Cache::get($cacheKey);
        $text = is_array($cached) ? trim((string) ($cached['text'] ?? '')) : '';
        if ($text === '') {
            $text = trim((string) ($this->compose($computer, $booking, $snap) ?? ''));
            if ($text === '') {
                return null;
            }
            Cache::put($cacheKey, ['text' => $text], self::LINE_TTL_SECONDS);
        }

        if (Cache::has($heardKey)) {
            return null;
        }
        Cache::put($heardKey, 1, self::LINE_TTL_SECONDS);

        return $text;
    }

    /**
     * @param  array<string, mixed>  $snap
     */
    public function compose(Computer $computer, Booking $booking, array $snap): ?string
    {
        if (! $this->isCs2Freeze($snap)) {
            return null;
        }

        $wallets = $this->partyWallets($computer, $booking, $snap);
        if (count($wallets) < 2) {
            return null;
        }

        $sum = 0;
        foreach ($wallets as $row) {
            $sum += $row['money'];
        }
        $features = app(\App\Services\ClubFeatureService::class);
        $clubId = $features->clubIdForComputer($computer);
        $ecoPer = max(500, $features->int($clubId, 'coach_whisper', 'eco_per_player', self::ECO_BANK_PER_PLAYER));
        if ($sum < count($wallets) * $ecoPer) {
            return self::ECO_LINE;
        }

        return $this->dropLine($wallets, $clubId);
    }

    /**
     * @param  list<array{computer_id:int,pc_name:string,money:int,has_awp:bool}>  $wallets
     */
    private function dropLine(array $wallets, ?int $clubId = null): ?string
    {
        usort($wallets, function (array $a, array $b) {
            if ($a['money'] !== $b['money']) {
                return $b['money'] <=> $a['money'];
            }

            return $a['computer_id'] <=> $b['computer_id'];
        });

        $donor = $wallets[0];
        $donorMin = max(
            self::AWP_COST,
            app(\App\Services\ClubFeatureService::class)->int(
                $clubId,
                'coach_whisper',
                'drop_donor_min',
                self::DROP_DONOR_MIN
            )
        );
        if ($donor['money'] < $donorMin) {
            return null;
        }

        $receiver = null;
        foreach (array_reverse($wallets) as $row) {
            if ((int) $row['computer_id'] === (int) $donor['computer_id']) {
                continue;
            }
            if ($row['has_awp']) {
                continue;
            }
            if ($row['money'] >= self::AWP_COST) {
                continue;
            }
            $receiver = $row;
            break;
        }
        if ($receiver === null) {
            return null;
        }

        $pc = trim((string) $receiver['pc_name']);
        if ($pc === '') {
            $pc = 'ПК-'.$receiver['computer_id'];
        }

        return 'Скинь AWP на '.$pc;
    }

    /**
     * @param  array<string, mixed>  $snap
     * @return list<array{computer_id:int,pc_name:string,money:int,has_awp:bool}>
     */
    private function partyWallets(Computer $computer, Booking $booking, array $snap): array
    {
        $ids = $this->partyComputerIds($booking);
        if (count($ids) < 2) {
            return [];
        }

        $myTeam = strtolower((string) ($snap['team'] ?? ''));
        $out = [];
        foreach ($ids as $id) {
            $row = $this->gsi->get($id) ?? [];
            if ($id === (int) $computer->id) {
                $row = array_merge($row, $snap);
            }
            if (($row['game'] ?? 'cs2') === 'dota') {
                continue;
            }
            if (! ($row['in_match'] ?? false) && strtolower((string) ($row['event'] ?? '')) !== 'freezetime') {
                continue;
            }
            if (! array_key_exists('money', $row) || $row['money'] === null || $row['money'] === '') {
                continue;
            }
            $theirTeam = strtolower((string) ($row['team'] ?? ''));
            if ($myTeam !== '' && $theirTeam !== '' && $theirTeam !== $myTeam) {
                continue;
            }
            $pcName = trim((string) ($row['pc_name'] ?? ''));
            if ($pcName === '') {
                $pcName = $id === (int) $computer->id
                    ? (string) $computer->name
                    : (string) (Computer::query()->find($id)?->name ?? '');
            }
            $out[] = [
                'computer_id' => $id,
                'pc_name' => $pcName,
                'money' => (int) $row['money'],
                'has_awp' => str_contains(strtolower((string) ($row['weapon'] ?? '')), 'awp'),
            ];
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private function partyComputerIds(?Booking $booking): array
    {
        $groupId = (int) ($booking?->booking_group_id ?? 0);
        if ($groupId < 1) {
            return [];
        }

        return Booking::query()
            ->where('booking_group_id', $groupId)
            ->whereIn('status', ['confirmed', 'paid', 'active'])
            ->pluck('computer_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
