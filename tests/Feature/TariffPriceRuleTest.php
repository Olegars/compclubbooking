<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Models\DayGroup;
use App\Models\Tariff;
use App\Models\TariffPrice;
use App\Models\Zone;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TariffPriceRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_saves_price_rule_without_not_found(): void
    {
        [$owner, $club, $tariff, $zone, $dayGroup] = $this->seedEditor();

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/tariffs?club='.$club->id.'&tariff='.$tariff->id)
            ->post('/admin/tariff-prices', [
                'tariff_id' => $tariff->id,
                'club_id' => $club->id,
                'zone_id' => $zone->id,
                'day_group_id' => $dayGroup->id,
                'time_start' => 0,
                'time_end' => 1440,
                'price' => 300,
            ])
            ->assertRedirect(route('admin.tariffs', [
                'club' => $club->id,
                'tariff' => $tariff->id,
            ]));

        $this->assertDatabaseHas('tariff_prices', [
            'tariff_id' => $tariff->id,
            'club_id' => $club->id,
            'zone_id' => $zone->id,
            'day_group_id' => $dayGroup->id,
            'time_start' => 0,
            'time_end' => 1440,
            'price' => 300,
        ]);
    }

    public function test_nested_rules_url_still_creates_price(): void
    {
        [$owner, $club, $tariff, $zone, $dayGroup] = $this->seedEditor();

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/tariffs/'.$tariff->id.'/rules', [
                'club_id' => $club->id,
                'zone_id' => $zone->id,
                'day_group_id' => $dayGroup->id,
                'time_start' => 0,
                'time_end' => 1440,
                'price' => 250,
            ])
            ->assertRedirect(route('admin.tariffs', [
                'club' => $club->id,
                'tariff' => $tariff->id,
            ]));

        $this->assertDatabaseHas('tariff_prices', [
            'tariff_id' => $tariff->id,
            'price' => 250,
        ]);
    }

    public function test_get_on_rules_url_is_not_found(): void
    {
        [$owner, , $tariff] = $this->seedEditor();

        $this->actingAs($owner, 'admin')
            ->get('/admin/tariffs/'.$tariff->id.'/rules')
            ->assertNotFound();
    }

    public function test_missing_tariff_returns_validation_not_404(): void
    {
        [$owner, $club, , $zone, $dayGroup] = $this->seedEditor();

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/tariffs')
            ->post('/admin/tariff-prices', [
                'tariff_id' => 999999,
                'club_id' => $club->id,
                'zone_id' => $zone->id,
                'day_group_id' => $dayGroup->id,
                'time_start' => 0,
                'time_end' => 1440,
                'price' => 300,
            ])
            ->assertRedirect('/admin/tariffs')
            ->assertSessionHasErrors('tariff_id');

        $this->assertSame(0, TariffPrice::query()->count());
    }

    /**
     * @return array{0: Admin, 1: Club, 2: Tariff, 3: Zone, 4: DayGroup}
     */
    private function seedEditor(): array
    {
        $club = Club::query()->first() ?? Club::query()->create([
            'name' => '0451',
            'slug' => 'club-'.uniqid(),
            'type' => 'club',
        ]);

        $owner = Admin::query()->create([
            'name' => 'Owner',
            'email' => 'owner.'.uniqid().'@tariff.test',
            'password' => 'password',
            'role' => 'owner',
            'club_id' => null,
        ]);

        $tariff = Tariff::query()->create([
            'name' => 'Почасовой',
            'threshold_hours' => 1,
            'price_per_package' => 0,
            'is_active' => true,
        ]);

        $zone = Zone::query()->first() ?? Zone::query()->create([
            'name' => 'Сингл',
            'slug' => 'singl-'.uniqid(),
            'color' => '#38bdf8',
        ]);

        $dayGroup = DayGroup::query()->first() ?? DayGroup::query()->create([
            'name' => 'Все дни',
            'color' => '#22c55e',
            'weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'sort' => 5,
        ]);

        return [$owner, $club, $tariff, $zone, $dayGroup];
    }
}
