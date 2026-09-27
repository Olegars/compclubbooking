<?php

namespace Tests\Feature;

use App\Models\Achievement;
use App\Models\Admin;
use App\Models\BattlePassLevel;
use App\Models\BattlePassSeason;
use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\CosmeticFrame;
use App\Models\User;
use App\Models\UserBattlePass;
use App\Services\AchievementService;
use App\Services\BattlePassService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BattlePassGrantTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_hours_grant_xp_minutes_frame_and_a_single_night_discount(): void
    {
        Carbon::setTestNow('2026-09-16 12:00:00');
        $club = Club::create(['name' => 'BP', 'slug' => 'bp']);
        $user = User::create([
            'name' => 'Игрок',
            'phone' => '79001110001',
            'email' => 'bp@club.test',
            'password' => 'password',
        ]);
        $pc = Computer::create(['club_id' => $club->id, 'name' => 'PC-1', 'status' => 'free', 'kind' => 'pc']);
        $frame = CosmeticFrame::create(['club_id' => $club->id, 'slug' => 'ring', 'name' => 'Кольцо', 'is_active' => true]);
        $season = BattlePassSeason::create([
            'club_id' => $club->id,
            'title' => 'Сезон',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-01',
            'status' => BattlePassSeason::LIVE,
            'is_active' => true,
            'claim_grace_days' => 14,
        ]);
        BattlePassLevel::create([
            'season_id' => $season->id,
            'level' => 1,
            'xp_required' => 50,
            'reward_kind' => 'session_minutes',
            'reward_payload' => ['minutes' => 60],
        ]);
        BattlePassLevel::create([
            'season_id' => $season->id,
            'level' => 2,
            'xp_required' => 80,
            'reward_kind' => 'cosmetic_frame',
            'reward_payload' => ['frame_id' => $frame->id],
        ]);
        BattlePassLevel::create([
            'season_id' => $season->id,
            'level' => 3,
            'xp_required' => 100,
            'reward_kind' => 'tariff_discount',
            'reward_payload' => ['percent' => 10, 'ttl_days' => 30, 'zone' => 'night'],
        ]);
        Achievement::create([
            'title' => '10 часов',
            'description' => '',
            'source_kind' => 'club',
            'type' => Achievement::TYPE_PLAY_HOURS,
            'target_value' => 10,
            'period' => Achievement::PERIOD_WEEKLY,
            'reward_type' => Achievement::REWARD_DEPOSIT,
            'reward_value' => 100,
            'xp' => 100,
            'night_start' => 22,
            'night_end' => 6,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $start = Carbon::parse('2026-09-15 10:00:00');
        Booking::create([
            'user_id' => $user->id,
            'computer_id' => $pc->id,
            'pc_ids' => [(string) $pc->id],
            'date' => '2026-09-15',
            'start_time' => 10,
            'duration' => 10,
            'price' => 100,
            'price_minor' => 10000,
            'status' => 'completed',
            'pin_code' => '1111',
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(10),
            'actual_started_at' => $start,
            'actual_ended_at' => $start->copy()->addHours(10),
        ]);

        app(AchievementService::class)->evaluateForUser($user);

        $progress = UserBattlePass::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($progress);
        $this->assertSame(100, (int) $progress->xp);
        $this->assertSame(3, (int) $progress->level);

        $this->actingAs($user)->post('/account/battle-pass/claim', ['level' => 1])->assertRedirect();
        $this->assertDatabaseHas('bonus_logs', [
            'user_id' => $user->id,
            'minutes' => 60,
            'source' => 'battle_pass',
        ]);

        $this->actingAs($user)->post('/account/battle-pass/claim', ['level' => 2])->assertRedirect();
        $booking = Booking::create([
            'user_id' => $user->id,
            'computer_id' => $pc->id,
            'pc_ids' => [(string) $pc->id],
            'date' => now()->toDateString(),
            'start_time' => 12,
            'duration' => 1,
            'price' => 100,
            'price_minor' => 10000,
            'status' => 'active',
            'pin_code' => '2222',
            'starts_at' => now()->subMinutes(5),
            'ends_at' => now()->addHour(),
            'actual_started_at' => now()->subMinutes(5),
        ]);
        $this->getJson('/api/shell/balance?terminal_id='.$pc->id.'&booking_id='.$booking->id)
            ->assertOk()
            ->assertJsonPath('profile.frame_id', $frame->id);

        $this->actingAs($user)->post('/account/battle-pass/claim', ['level' => 3])->assertRedirect();
        $pass = app(BattlePassService::class);
        $night = Carbon::parse('2026-09-16 23:00:00');
        $once = $pass->applyToQuote($user, ['total_minor' => 100000, 'duration_minutes' => 90], $night, true);
        $this->assertSame(90000, $once['total_minor']);
        $twice = $pass->applyToQuote($user, ['total_minor' => 100000, 'duration_minutes' => 90], $night, true);
        $this->assertSame(100000, $twice['total_minor']);

        $admin = Admin::create([
            'name' => 'Супер',
            'email' => 'bp-admin@club.test',
            'password' => 'password',
            'role' => Admin::ROLE_SUPERVISOR,
            'club_id' => $club->id,
        ]);
        $this->actingAs($admin, 'admin')->get('/admin/achievements?tab=pass')->assertOk();
    }
}
