<?php

namespace App\Services;

use App\Models\Club;
use App\Models\Computer;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Публичная сводка занятости клуба: сколько мест свободно прямо сейчас,
 * с разбивкой по зонам карты. Читает те же данные, что и бронирование,
 * поэтому цифра на лендинге не расходится с реальной доступностью.
 */
class ClubOccupancyService
{
    /** Окно, в течение которого место должно быть свободно, чтобы считаться доступным. */
    private const LOOKAHEAD_MINUTES = 15;

    private const OFFLINE_STATUSES = ['maintenance'];

    public function __construct(
        private readonly MapZoneResolver $zoneResolver,
        private readonly GameBookingService $bookings,
    ) {}

    /**
     * @return array{
     *     total: int, free: int, busy: int, offline: int,
     *     kinds: array<string, array{total: int, free: int}>,
     *     zones: list<array<string, mixed>>,
     *     free_seat_ids: list<int>,
     *     occupied_seat_ids: list<int>
     * }
     */
    public function summary(Club $club, ?CarbonImmutable $at = null): array
    {
        $at = $at ?: CarbonImmutable::now();
        $computers = $club->computers()->orderBy('name')->get();

        if ($computers->isEmpty()) {
            return [
                'total' => 0,
                'free' => 0,
                'busy' => 0,
                'offline' => 0,
                'kinds' => [],
                'zones' => [],
                'free_seat_ids' => [],
                'occupied_seat_ids' => [],
            ];
        }

        $offline = $computers->filter(fn (Computer $c) => in_array((string) $c->status, self::OFFLINE_STATUSES, true));
        $sellable = $computers->reject(fn (Computer $c) => in_array((string) $c->status, self::OFFLINE_STATUSES, true));

        $bookedIds = $this->bookedComputerIds($sellable, $at);
        $isBusy = fn (Computer $c) => in_array((int) $c->id, $bookedIds, true) || (string) $c->status !== 'available';

        $zoneBySeat = $this->zoneResolver->resolveForComputers($club, $sellable);
        $zoneMeta = Zone::query()->get()->keyBy(fn (Zone $z) => strtolower((string) $z->slug));

        $zones = [];
        $kinds = [];
        $freeIds = [];

        foreach ($sellable as $computer) {
            $slug = $zoneBySeat[(int) $computer->id] ?? MapZoneResolver::FALLBACK_CATEGORY;
            $kind = (string) ($computer->kind ?: Computer::KIND_PC);
            $busy = $isBusy($computer);

            $zones[$slug] ??= [
                'slug' => $slug,
                'name' => (string) ($zoneMeta[$slug]->name ?? ucfirst($slug)),
                'color' => (string) ($zoneMeta[$slug]->color ?? '#22c55e'),
                'seats_total' => 0,
                'seats_free' => 0,
                'kinds' => [],
                'free_seat_id' => null,
            ];

            $zones[$slug]['seats_total']++;
            $zones[$slug]['kinds'][$kind] ??= ['total' => 0, 'free' => 0];
            $zones[$slug]['kinds'][$kind]['total']++;

            $kinds[$kind] ??= ['total' => 0, 'free' => 0];
            $kinds[$kind]['total']++;

            if (! $busy) {
                $zones[$slug]['seats_free']++;
                $zones[$slug]['kinds'][$kind]['free']++;
                $zones[$slug]['free_seat_id'] ??= (int) $computer->id;
                $kinds[$kind]['free']++;
                $freeIds[] = (int) $computer->id;
            }
        }

        $total = $sellable->count();
        $free = count($freeIds);

        return [
            'total' => $total,
            'free' => $free,
            'busy' => $total - $free,
            'offline' => $offline->count(),
            'kinds' => $kinds,
            'zones' => array_values($zones),
            'free_seat_ids' => $freeIds,
            'occupied_seat_ids' => $computers
                ->reject(fn (Computer $c) => in_array((int) $c->id, $freeIds, true))
                ->map(fn (Computer $c) => (int) $c->id)
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, Computer>  $computers
     * @return list<int>
     */
    private function bookedComputerIds(Collection $computers, CarbonImmutable $at): array
    {
        $ids = $computers->map(fn (Computer $c) => (int) $c->id)->values()->all();
        if ($ids === []) {
            return [];
        }

        return $this->bookings->occupiedComputerIds(
            $ids,
            $at,
            $at->addMinutes(self::LOOKAHEAD_MINUTES)
        );
    }
}
