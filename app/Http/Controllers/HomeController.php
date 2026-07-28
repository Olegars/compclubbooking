<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Computer;
use App\Models\Tariff;
use App\Services\ClubOccupancyService;
use App\Services\TariffService;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function __construct(
        private readonly TariffService $tariffs,
        private readonly ClubOccupancyService $occupancy,
    ) {}

    public function index()
    {
        $club = Club::query()->orderBy('id')->first();

        if (! $club) {
            return Inertia::render('Home/Index', [
                'club' => null,
                'occupancy' => null,
                'zones' => [],
                'games' => [],
                'map' => null,
                'contacts' => $this->contacts(null),
            ]);
        }

        $occupancy = $this->occupancy->summary($club);
        $computers = $club->computers()->orderBy('name')->get();

        return Inertia::render('Home/Index', [
            'club' => [
                'name' => (string) $club->name,
                'slug' => (string) $club->slug,
                'address' => (string) ($club->address ?: config('club.address')),
            ],
            'occupancy' => [
                'total' => $occupancy['total'],
                'free' => $occupancy['free'],
                'busy' => $occupancy['busy'],
                'kinds' => $occupancy['kinds'],
            ],
            'zones' => $this->zonesWithPricing($occupancy['zones']),
            'games' => $this->games($club),
            'map' => [
                'config' => $club->map_config,
                'viewbox' => (string) $club->viewbox,
                'computers' => $computers->map(fn (Computer $c) => [
                    'id' => (int) $c->id,
                    'name' => (string) $c->name,
                    'x' => (float) $c->x,
                    'y' => (float) $c->y,
                    'kind' => (string) ($c->kind ?: Computer::KIND_PC),
                ])->values()->all(),
                'occupied_ids' => array_map('strval', $occupancy['occupied_seat_ids']),
            ],
            'contacts' => $this->contacts($club),
            'ps5SurchargePerHour' => (float) config('booking.ps5_surcharge_per_hour', 0),
            'minAge' => (int) config('club.min_age'),
        ]);
    }

    /**
     * Занятость зон + реальные цены из тарифной сетки.
     * Зона без активного тарифа отдаёт price_per_hour = null, чтобы фронт
     * не показывал вымышленную цифру.
     *
     * @param  list<array<string, mixed>>  $occupancyZones
     * @return list<array<string, mixed>>
     */
    private function zonesWithPricing(array $occupancyZones): array
    {
        $zones = collect($occupancyZones)->map(function (array $zone) {
            $slug = (string) $zone['slug'];
            $grid = $this->tariffs->gridForCategory($slug);
            $hasTariff = Tariff::query()
                ->where('is_active', true)
                ->where('category', $slug)
                ->exists();

            $hourly = (float) $grid['hourly_rate'];

            $packages = collect($grid['packages'])->map(function (array $package) use ($hourly) {
                $full = $hourly * (int) $package['hours'];
                $discount = $full > 0
                    ? (int) round(max(0, (1 - ((float) $package['cost'] / $full)) * 100))
                    : 0;

                return [
                    'id' => (int) $package['id'],
                    'title' => (string) $package['title'],
                    'hours' => (int) $package['hours'],
                    'cost' => (float) $package['cost'],
                    'hourly_equivalent' => (float) $package['hourly_equivalent'],
                    'discount_pct' => $discount,
                ];
            })->values()->all();

            return [
                ...$zone,
                'price_per_hour' => $hasTariff ? (int) round($hourly) : null,
                'packages' => $packages,
            ];
        });

        return $zones
            ->sortBy(fn (array $zone) => $zone['price_per_hour'] ?? PHP_INT_MAX)
            ->values()
            ->all();
    }

    /**
     * Игры клуба: платные (их стоит бронировать заранее) идут первыми.
     * Одна игра может быть подключена несколько раз — дедуплицируем по названию,
     * оставляя платное предложение как более информативное.
     *
     * @return list<array<string, mixed>>
     */
    private function games(Club $club): array
    {
        return $club->gameOffers()
            ->where('is_enabled', true)
            ->with('game')
            ->get()
            ->filter(fn ($offer) => (bool) $offer->game)
            ->map(fn ($offer) => [
                'id' => (int) $offer->game_id,
                'title' => (string) $offer->game->title,
                'platform' => (string) ($offer->game->platform ?? ''),
                'poster' => $offer->game->poster,
                'is_paid' => (bool) $offer->is_paid,
                'price_rub' => $offer->is_paid
                    ? round(((int) $offer->unit_price_minor) / 100, 2)
                    : 0.0,
                'unit_minutes' => (int) ($offer->billing_unit_minutes ?: 60),
            ])
            ->sortByDesc('is_paid')
            ->unique(fn (array $game) => mb_strtolower(trim($game['title'])))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function contacts(?Club $club): array
    {
        $socials = collect(config('club.socials', []))
            ->filter(fn ($url) => filled($url))
            ->all();

        $legal = collect(config('club.legal', []))
            ->filter(fn ($value) => filled($value))
            ->all();

        return [
            'club_name' => (string) ($club?->name ?: 'Sector 0451'),
            'city' => (string) config('club.city'),
            'address' => (string) ($club?->address ?: config('club.address')),
            'hours' => (string) config('club.hours'),
            'phone' => (string) config('club.phone'),
            'map_url' => (string) config('club.map_url'),
            'socials' => $socials,
            'legal' => $legal,
        ];
    }
}
