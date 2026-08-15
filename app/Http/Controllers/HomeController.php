<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Computer;
use App\Models\Zone;
use App\Services\ClubOccupancyService;
use App\Services\MapPresentationService;
use App\Services\ReviewBonusService;
use App\Services\TariffService;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function __construct(
        private readonly TariffService $tariffs,
        private readonly ClubOccupancyService $occupancy,
        private readonly MapPresentationService $mapPresentation,
        private readonly ReviewBonusService $reviews,
    ) {}

    public function index()
    {
        $club = Club::query()->orderBy('id')->first();
        $siteReviews = $this->reviews->siteReviews()->map(fn ($r) => [
            'id' => (int) $r->id,
            'author' => (string) ($r->author_name ?: 'Гость'),
            'text' => (string) $r->text,
            'rating' => (float) $r->rating,
            'source' => (string) $r->source,
            'url' => $r->url,
        ])->values()->all();

        if (! $club) {
            return Inertia::render('Home/Index', [
                'club' => null,
                'occupancy' => null,
                'zones' => [],
                'games' => [],
                'map' => null,
                'contacts' => $this->contacts(null),
                'reviews' => $siteReviews,
                'reviews_map_url' => config('club.reviews.yandex_maps_url') ?: config('club.map_url') ?: null,
            ]);
        }

        $occupancy = $this->occupancy->summary($club);
        $computers = $club->computers()->orderBy('name')->get();
        $mapConfig = is_array($club->map_config) ? $club->map_config : [];
        $mapConfig = $this->mapPresentation->decorate($mapConfig, (int) $club->id);

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
            'zones' => $this->zonesWithPricing((int) $club->id, $occupancy['zones']),
            'games' => $this->games($club),
            'map' => [
                'config' => $mapConfig,
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
            'minAge' => (int) config('club.min_age'),
            'reviews' => $siteReviews,
            'reviews_map_url' => config('club.reviews.yandex_maps_url') ?: config('club.map_url') ?: null,
        ]);
    }

    /**
     * Занятость зон + базовые цены типа помещения (без «+» конкретных комнат).
     *
     * @param  list<array<string, mixed>>  $occupancyZones
     * @return list<array<string, mixed>>
     */
    private function zonesWithPricing(int $clubId, array $occupancyZones): array
    {
        $zoneIdBySlug = Zone::query()->pluck('id', 'slug');

        $zones = collect($occupancyZones)->map(function (array $zone) use ($clubId, $zoneIdBySlug) {
            $zoneId = $zoneIdBySlug->get(strtolower((string) $zone['slug']));
            $zoneId = $zoneId ? (int) $zoneId : null;

            $grid = $this->tariffs->gridForZone($clubId, $zoneId);
            $hasTariff = $this->tariffs->hasPricing($clubId, $zoneId);
            $hourly = (float) $grid['base_hourly_rate'];

            $packages = collect($grid['packages'])->map(function (array $package) use ($hourly) {
                $full = $hourly * (int) $package['hours'];
                $discount = $full > 0
                    ? (int) round(max(0, (1 - ((float) $package['base_cost'] / $full)) * 100))
                    : 0;

                return [
                    'id' => (int) $package['id'],
                    'title' => (string) $package['title'],
                    'hours' => (int) $package['hours'],
                    'cost' => (float) $package['base_cost'],
                    'hourly_equivalent' => (int) $package['hours'] > 0
                        ? round((float) $package['base_cost'] / (int) $package['hours'], 2)
                        : $hourly,
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
            'club_name' => (string) ($club?->name ?: \App\Support\ClubBrand::name()),
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
