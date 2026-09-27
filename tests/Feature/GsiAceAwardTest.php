<?php

namespace Tests\Feature;

use App\Jobs\SyncIdentityJob;
use App\Models\Achievement;
use App\Models\BattlePassSeason;
use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\User;
use App\Models\UserBattlePass;
use App\Models\UserIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class GsiAceAwardTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_ace_posts_grant_xp_once(): void
    {
        $club = Club::create(['name' => 'GSI', 'slug' => 'gsi']);
        $user = User::create([
            'name' => 'Эйс',
            'phone' => '79001110003',
            'email' => 'ace@club.test',
            'password' => 'password',
        ]);
        $pc = Computer::create(['club_id' => $club->id, 'name' => 'PC-08', 'status' => 'busy', 'kind' => 'pc']);
        $start = CarbonImmutable::now()->subMinutes(20);
        $booking = Booking::create([
            'user_id' => $user->id,
            'computer_id' => $pc->id,
            'pc_ids' => [(string) $pc->id],
            'date' => $start->toDateString(),
            'start_time' => 12,
            'duration' => 2,
            'price' => 200,
            'price_minor' => 20000,
            'status' => 'active',
            'pin_code' => '4444',
            'starts_at' => $start,
            'ends_at' => $start->addHours(2),
            'actual_started_at' => $start,
        ]);
        BattlePassSeason::create([
            'club_id' => $club->id,
            'title' => 'GSI',
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addMonth()->toDateString(),
            'status' => BattlePassSeason::LIVE,
            'is_active' => true,
            'claim_grace_days' => 14,
        ]);
        Achievement::create([
            'title' => 'Эйс',
            'description' => '',
            'source_kind' => 'gsi_cs2',
            'code' => 'ace',
            'type' => 'gsi_event',
            'metric' => 'ace',
            'target_value' => 1,
            'period' => Achievement::PERIOD_WEEKLY,
            'reward_type' => Achievement::REWARD_BONUS,
            'reward_value' => 0,
            'reward_kind' => 'none',
            'xp' => 25,
            'night_start' => 22,
            'night_end' => 6,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $payload = [
            'terminal_id' => $pc->id,
            'booking_id' => $booking->id,
            'event' => 'achievement',
            'game' => 'cs2',
            'achievement_code' => 'ace',
            'match_round_key' => 'cs2:match:3',
        ];
        foreach ([1, 2, 3] as $i) {
            $this->postJson('/api/shell/gsi', $payload)->assertOk();
        }

        $this->assertSame(1, \App\Models\GsiAwardDedup::query()->count());
        $this->assertSame(25, (int) UserBattlePass::query()->where('user_id', $user->id)->value('xp'));
    }

    public function test_opendota_limiter_does_not_break_shell_balance(): void
    {
        $club = Club::create(['name' => 'Lim', 'slug' => 'lim']);
        $user = User::create([
            'name' => 'Лимит',
            'phone' => '79001110004',
            'email' => 'lim@club.test',
            'password' => 'password',
        ]);
        $pc = Computer::create(['club_id' => $club->id, 'name' => 'PC', 'status' => 'busy', 'kind' => 'pc']);
        $start = CarbonImmutable::now()->subMinutes(5);
        $booking = Booking::create([
            'user_id' => $user->id,
            'computer_id' => $pc->id,
            'pc_ids' => [(string) $pc->id],
            'date' => $start->toDateString(),
            'start_time' => 12,
            'duration' => 1,
            'price' => 100,
            'price_minor' => 10000,
            'status' => 'active',
            'pin_code' => '5555',
            'starts_at' => $start,
            'ends_at' => $start->addHour(),
            'actual_started_at' => $start,
        ]);
        UserIdentity::create([
            'user_id' => $user->id,
            'provider' => 'opendota',
            'external_id' => '76561198000000099',
        ]);
        config(['services.loyalty.opendota' => true]);
        for ($i = 0; $i < 50; $i++) {
            RateLimiter::hit('loyalty-opendota', 60);
        }
        (new SyncIdentityJob($user->id, 'opendota'))->handle();

        $this->getJson('/api/shell/balance?terminal_id='.$pc->id.'&booking_id='.$booking->id)
            ->assertOk()
            ->assertJsonPath('status', 'success');
        $this->assertNull(UserIdentity::query()->value('synced_at'));
    }
}
