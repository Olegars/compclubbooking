<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Computer;

/**
 * Компания: соседние места в одной зоне и состав пати по booking_group.
 */
class PartyBookingService
{
    /**
     * @param  iterable<Computer|object>  $computers
     * @param  list<int|string>  $occupiedIds
     * @return list<int>
     */
    public function suggestConsecutive(iterable $computers, array $occupiedIds, int $count, ?int $anchorId = null): array
    {
        $count = max(2, min(12, $count));
        $occupied = [];
        foreach ($occupiedIds as $id) {
            $occupied[(int) $id] = true;
        }

        $pcs = [];
        foreach ($computers as $pc) {
            $kind = (string) ($pc->kind ?? 'pc');
            if ($kind !== '' && $kind !== 'pc') {
                continue;
            }
            $pcs[] = $pc;
        }
        if ($pcs === []) {
            return [];
        }

        $groups = [];
        foreach ($pcs as $pc) {
            $key = (string) ($pc->type ?? '').'|'.(string) ($pc->space_id ?? '0');
            $groups[$key][] = $pc;
        }

        $anchor = $anchorId ? (int) $anchorId : null;
        $preferred = [];
        $rest = [];
        foreach ($groups as $group) {
            $ids = $this->bestRun($group, $occupied, $count, $anchor);
            if ($ids === []) {
                continue;
            }
            $hasAnchor = $anchor && in_array($anchor, $ids, true);
            if ($hasAnchor) {
                $preferred[] = $ids;
            } else {
                $rest[] = $ids;
            }
        }

        return $preferred[0] ?? $rest[0] ?? [];
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    public function seatsForBooking(Booking $booking): array
    {
        $ids = [];
        if ($booking->booking_group_id) {
            $ids = Booking::query()
                ->where('booking_group_id', $booking->booking_group_id)
                ->whereIn('status', ['confirmed', 'paid', 'active'])
                ->pluck('computer_id')
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all();
        }
        if ($ids === [] && $booking->computer_id) {
            $ids = [(int) $booking->computer_id];
        }

        if ($ids === []) {
            return [];
        }

        $names = Computer::query()->whereIn('id', $ids)->pluck('name', 'id');
        $out = [];
        foreach ($ids as $id) {
            $out[] = [
                'id' => $id,
                'name' => (string) ($names[$id] ?? ('ПК №'.$id)),
            ];
        }
        usort($out, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $out;
    }

    /**
     * @return array{count:int,names:list<string>,computer_ids:list<int>}
     */
    public function payloadForBooking(?Booking $booking): array
    {
        if (! $booking) {
            return ['count' => 0, 'names' => [], 'computer_ids' => []];
        }
        $seats = $this->seatsForBooking($booking);

        return [
            'count' => count($seats),
            'names' => array_column($seats, 'name'),
            'computer_ids' => array_column($seats, 'id'),
        ];
    }

    /**
     * @param  list<object>  $group
     * @param  array<int, bool>  $occupied
     * @return list<int>
     */
    private function bestRun(array $group, array $occupied, int $count, ?int $anchor): array
    {
        usort($group, function ($a, $b) {
            $na = $this->seatNumber((string) ($a->name ?? ''));
            $nb = $this->seatNumber((string) ($b->name ?? ''));
            if ($na !== null && $nb !== null && $na !== $nb) {
                return $na <=> $nb;
            }

            return strnatcasecmp((string) ($a->name ?? ''), (string) ($b->name ?? ''));
        });

        $free = [];
        foreach ($group as $pc) {
            $id = (int) $pc->id;
            if (isset($occupied[$id])) {
                continue;
            }
            $free[] = $pc;
        }
        if (count($free) < $count) {
            return [];
        }

        $best = [];
        for ($i = 0; $i <= count($free) - $count; $i++) {
            $slice = array_slice($free, $i, $count);
            if (! $this->isConsecutive($slice)) {
                continue;
            }
            $ids = array_map(fn ($pc) => (int) $pc->id, $slice);
            $hasAnchor = $anchor && in_array($anchor, $ids, true);
            if ($hasAnchor) {
                return $ids;
            }
            if ($best === []) {
                $best = $ids;
            }
        }

        return $best;
    }

    /**
     * @param  list<object>  $slice
     */
    private function isConsecutive(array $slice): bool
    {
        $nums = [];
        foreach ($slice as $pc) {
            $n = $this->seatNumber((string) ($pc->name ?? ''));
            if ($n === null) {
                return true;
            }
            $nums[] = $n;
        }
        for ($i = 1; $i < count($nums); $i++) {
            if ($nums[$i] !== $nums[$i - 1] + 1) {
                return false;
            }
        }

        return true;
    }

    private function seatNumber(string $name): ?int
    {
        if (preg_match('/(\d+)\s*$/', $name, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
