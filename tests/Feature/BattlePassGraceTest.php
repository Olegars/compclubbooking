<?php

namespace Tests\Feature;

use App\Models\BattlePassLevel;
use App\Models\BattlePassSeason;
use App\Models\Club;
use App\Models\Computer;
use App\Models\User;
use App\Services\BattlePassService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BattlePassGraceTest extends TestCase
{
    use RefreshDatabase;

    public function test_claim_works_inside_grace_and_the_coupon_outlives_the_season(): void
    {
        Carbon::setTestNow('2026-09-01 12:00:00');
        $club = Club::create(['name' => 'Grace', 'slug' => 'grace']);
        $user = User::create([
            'name' => 'Гость',
            'phone' => '79001110002',
            'email' => 'grace@club.test',
            'password' => 'password',
        ]);
        Computer::create(['club_id' => $club->id, 'name' => 'PC', 'status' => 'free', 'kind' => 'pc']);
        \App\Models\Booking::create([
            'user_id' => $user->id,
            'computer_id' => Computer::query()->value('id'),
            'pc_ids' => ['1'],
            'date' => '2026-09-01',
            'start_time' => 12,
            'duration' => 1,
            'price' => 100,
            'price_minor' => 10000,
            'status' => 'completed',
            'pin_code' => '3333',
            'starts_at' => now()->subHour(),
            'ends_at' => now(),
        ]);
        $season = BattlePassSeason::create([
            'club_id' => $club->id,
            'title' => 'Осень',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-10',
            'status' => BattlePassSeason::LIVE,
            'is_active' => true,
            'claim_grace_days' => 14,
        ]);
        BattlePassLevel::create([
            'season_id' => $season->id,
            'level' => 1,
            'xp_required' => 10,
            'reward_kind' => 'tariff_discount',
            'reward_payload' => ['percent' => 10, 'ttl_days' => 30, 'zone' => 'any'],
        ]);
        $pass = app(BattlePassService::class);
        $pass->addXp($user, 10, $club->id);
        $pass->close($season->fresh());

        Carbon::setTestNow('2026-09-13 12:00:00');
        $this->actingAs($user)->post('/account/battle-pass/claim', ['level' => 1])->assertRedirect();
        $this->assertDatabaseHas('user_reward_grants', [
            'user_id' => $user->id,
            'reward_kind' => 'tariff_discount',
        ]);
        $grant = \App\Models\UserRewardGrant::query()->first();
        $this->assertTrue($grant->expires_at->greaterThan(Carbon::parse('2026-10-10')));

        Carbon::setTestNow('2026-09-26 12:00:00');
        $pass->rollSeasons($club->id);
        $this->assertSame(BattlePassSeason::ARCHIVED, $season->fresh()->status);
        $this->actingAs($user)->post('/account/battle-pass/claim', ['level' => 1])
            ->assertRedirect()
            ->assertSessionHas('error');

        $night = Carbon::parse('2026-09-26 15:00:00');
        $quote = $pass->applyToQuote($user->fresh(), ['total_minor' => 100000, 'duration_minutes' => 90], $night, true);
        $this->assertSame(90000, $quote['total_minor']);
    }
}
