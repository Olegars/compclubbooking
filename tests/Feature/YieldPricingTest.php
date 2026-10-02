<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\DayGroup;
use App\Models\Space;
use App\Models\Tariff;
use App\Models\TariffPrice;
use App\Models\User;
use App\Models\YieldRule;
use App\Models\Zone;
use App\Services\TariffService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class YieldPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_weekday_afternoon_below_20_percent_applies_happy_hour(): void
    {
        $at = CarbonImmutable::parse('2026-09-28 14:00:00', config('app.timezone'));
        CarbonImmutable::setTestNow($at);

        [$club, $zone] = $this->seedHall(seats: 5, busy: 0);
        $this->happyHour($club, $zone);

        $rate = app(TariffService::class)->hourlyRateRub((int) $club->id, (int) $zone->id, $at);

        $this->assertSame(150.0, $rate);
    }

    public function test_exactly_20_percent_does_not_discount(): void
    {
        $at = CarbonImmutable::parse('2026-09-28 14:00:00', config('app.timezone'));
        CarbonImmutable::setTestNow($at);

        [$club, $zone] = $this->seedHall(seats: 5, busy: 1);
        $this->happyHour($club, $zone);

        $rate = app(TariffService::class)->hourlyRateRub((int) $club->id, (int) $zone->id, $at);

        $this->assertSame(200.0, $rate);
    }

    public function test_friday_evening_high_demand_raises_the_rate(): void
    {
        $at = CarbonImmutable::parse('2026-10-02 20:00:00', config('app.timezone'));
        CarbonImmutable::setTestNow($at);

        [$club, $zone] = $this->seedHall(seats: 5, busy: 4);
        $this->fridaySurge($club);

        $rate = app(TariffService::class)->hourlyRateRub((int) $club->id, (int) $zone->id, $at);

        $this->assertSame(240.0, $rate);
    }

    public function test_future_slot_ignores_live_status_and_uses_bookings(): void
    {
        $now = CarbonImmutable::parse('2026-09-28 14:00:00', config('app.timezone'));
        $friday = CarbonImmutable::parse('2026-10-02 20:00:00', config('app.timezone'));
        CarbonImmutable::setTestNow($now);

        [$club, $zone, $computers] = $this->seedHall(seats: 5, busy: 5);
        $this->fridaySurge($club);

        $idle = app(TariffService::class)->hourlyRateRub((int) $club->id, (int) $zone->id, $friday);
        $this->assertSame(200.0, $idle);

        $user = User::query()->create([
            'name' => 'Guest',
            'phone' => '7900'.random_int(1000000, 9999999),
            'password' => 'password',
        ]);

        foreach (array_slice($computers, 0, 4) as $computer) {
            Booking::query()->create([
                'user_id' => $user->id,
                'computer_id' => $computer->id,
                'date' => $friday->toDateString(),
                'start_time' => 20,
                'duration' => 2,
                'price' => 200,
                'status' => 'confirmed',
                'starts_at' => $friday,
                'ends_at' => $friday->addHours(2),
            ]);
        }

        $busy = app(TariffService::class)->hourlyRateRub((int) $club->id, (int) $zone->id, $friday);

        $this->assertSame(240.0, $busy);
    }

    public function test_zone_rule_beats_a_higher_priority_club_rule(): void
    {
        $at = CarbonImmutable::parse('2026-09-28 14:00:00', config('app.timezone'));
        CarbonImmutable::setTestNow($at);

        [$club, $zone] = $this->seedHall(seats: 5, busy: 0);
        $this->happyHour($club, null, priority: 100, percent: -10);
        $this->happyHour($club, $zone, priority: 1, percent: -50);

        $rate = app(TariffService::class)->hourlyRateRub((int) $club->id, (int) $zone->id, $at);

        $this->assertSame(100.0, $rate);
    }

    public function test_package_price_stays_on_the_list_rate(): void
    {
        $at = CarbonImmutable::parse('2026-09-28 14:00:00', config('app.timezone'));
        CarbonImmutable::setTestNow($at);

        [$club, $zone] = $this->seedHall(seats: 5, busy: 0);
        $this->happyHour($club, $zone);

        $package = Tariff::query()->create([
            'name' => '3 часа',
            'threshold_hours' => 3,
            'price_per_package' => 0,
            'is_active' => true,
        ]);
        TariffPrice::query()->create([
            'tariff_id' => $package->id,
            'club_id' => $club->id,
            'zone_id' => $zone->id,
            'day_group_id' => DayGroup::query()->first()->id,
            'time_start' => 0,
            'time_end' => 1440,
            'price' => 500,
        ]);

        $quote = app(TariffService::class)->priceForSeatRub(
            (int) $club->id,
            (int) $zone->id,
            3,
            'packages',
            (int) $package->id,
            0,
            $at,
            $at->addHours(3)
        );

        $this->assertSame(500.0, (float) $quote['package_price']);
    }

    public function test_owner_saves_a_yield_rule(): void
    {
        [$club, $zone] = $this->seedHall(seats: 1, busy: 0);
        $owner = Admin::query()->create([
            'name' => 'Owner',
            'email' => 'owner.'.uniqid().'@yield.test',
            'password' => 'password',
            'role' => 'owner',
            'club_id' => null,
        ]);

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/tariffs?club='.$club->id)
            ->post('/admin/yield-rules', [
                'club_id' => $club->id,
                'zone_id' => $zone->id,
                'name' => 'Счастливый час',
                'kind' => 'discount',
                'is_active' => true,
                'weekdays' => [1, 2, 3, 4, 5],
                'time_start' => 600,
                'time_end' => 1020,
                'utilization_op' => 'below',
                'utilization_percent' => 20,
                'adjust_percent' => 25,
                'priority' => 10,
            ])
            ->assertRedirect();

        $saved = YieldRule::query()->where('club_id', $club->id)->first();
        $this->assertNotNull($saved);
        $this->assertSame((int) $zone->id, (int) $saved->zone_id);
        $this->assertSame('Счастливый час', $saved->name);
        $this->assertSame(-25.0, (float) $saved->adjust_percent);
    }

    /**
     * @return array{0: Club, 1: Zone, 2: list<Computer>}
     */
    private function seedHall(int $seats, int $busy): array
    {
        $club = Club::query()->create([
            'name' => '0451',
            'slug' => 'club-'.uniqid(),
            'type' => 'club',
        ]);
        $zone = Zone::query()->create([
            'name' => 'Сингл',
            'slug' => 'singl-'.uniqid(),
            'color' => '#38bdf8',
        ]);
        $space = Space::query()->create([
            'club_id' => $club->id,
            'zone_id' => $zone->id,
            'name' => 'Зал',
        ]);
        $dayGroup = DayGroup::query()->create([
            'name' => 'Все дни '.uniqid(),
            'color' => '#22c55e',
            'weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'sort' => 5,
        ]);
        $tariff = Tariff::query()->create([
            'name' => 'Почасовой',
            'threshold_hours' => 1,
            'price_per_package' => 0,
            'is_active' => true,
        ]);
        TariffPrice::query()->create([
            'tariff_id' => $tariff->id,
            'club_id' => $club->id,
            'zone_id' => $zone->id,
            'day_group_id' => $dayGroup->id,
            'time_start' => 0,
            'time_end' => 1440,
            'price' => 200,
        ]);

        $computers = [];
        for ($i = 0; $i < $seats; $i++) {
            $computers[] = Computer::query()->create([
                'club_id' => $club->id,
                'name' => str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                'space_id' => $space->id,
                'status' => $i < $busy ? 'occupied' : 'available',
            ]);
        }

        return [$club, $zone, $computers];
    }

    private function happyHour(Club $club, ?Zone $zone, int $priority = 10, float $percent = -25): void
    {
        YieldRule::query()->create([
            'club_id' => $club->id,
            'zone_id' => $zone?->id,
            'name' => 'Счастливый час',
            'kind' => 'discount',
            'is_active' => true,
            'weekdays' => [1, 2, 3, 4, 5],
            'time_start' => 10 * 60,
            'time_end' => 17 * 60,
            'utilization_op' => 'below',
            'utilization_percent' => 20,
            'adjust_percent' => $percent,
            'priority' => $priority,
        ]);
    }

    private function fridaySurge(Club $club): void
    {
        YieldRule::query()->create([
            'club_id' => $club->id,
            'zone_id' => null,
            'name' => 'Пятничный спрос',
            'kind' => 'surge',
            'is_active' => true,
            'weekdays' => [5],
            'time_start' => 18 * 60,
            'time_end' => 1440,
            'utilization_op' => 'above',
            'utilization_percent' => 70,
            'adjust_percent' => 20,
            'priority' => 20,
        ]);
    }
}
