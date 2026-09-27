<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\FaceitIdentity;
use App\Models\User;
use App\Services\ClubFeatureService;
use App\Services\LanLive\LanMatchmakingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaceitLfgRankTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = Club::create(['name' => 'LFG Club', 'slug' => 'lfg-faceit']);
        app(ClubFeatureService::class)->save($this->club->id, 'faceit', true, [
            'mode' => 'identity',
            'hub_id' => '',
            'organizer_id' => '',
            'game_id' => 'cs2',
            'sync_seated_minutes' => 15,
        ]);
        app(ClubFeatureService::class)->save($this->club->id, 'lfg', true, [
            'ttl_minutes' => 20,
            'rank_delta' => 1,
        ]);
    }

    public function test_linked_skill_replaces_the_faceit_stub_and_does_not_pair_level_10_with_2(): void
    {
        $high = $this->player('Хай', '79005550201', 10);
        $low = $this->player('Лоу', '79005550202', 2);
        $lfg = app(LanMatchmakingService::class);
        $a = $lfg->enqueue($high['user'], $high['pc'], $high['booking'], 'cs2', 'faceit');
        $b = $lfg->enqueue($low['user'], $low['pc'], $low['booking'], 'cs2', 'faceit');

        $this->assertSame(10, $a['rank_tier']);
        $this->assertSame(2, $b['rank_tier']);
        $this->assertSame('open', $a['status']);
        $this->assertSame('open', $b['status']);
    }

    public function test_unlinked_faceit_string_stays_a_stub_and_a_ban_blocks_the_queue(): void
    {
        $user = User::create([
            'name' => 'Без привязки',
            'phone' => '79005550203',
            'email' => 'nolink@club.test',
            'password' => 'password',
        ]);
        $pc = $this->pc('PC-03');
        $booking = $this->seat($user, $pc);
        $queue = app(LanMatchmakingService::class)->enqueue($user, $pc, $booking, 'cs2', 'faceit');
        $this->assertSame(7, $queue['rank_tier']);

        $banned = $this->player('Бан', '79005550204', 5);
        $banned['identity']->forceFill(['banned_until' => now()->addDay()])->save();
        $this->expectExceptionMessage('FACEIT бан');
        app(LanMatchmakingService::class)->enqueue($banned['user'], $banned['pc'], $banned['booking'], 'cs2', 'gold');
    }

    /**
     * @return array{user: User, pc: Computer, booking: Booking, identity: FaceitIdentity}
     */
    private function player(string $name, string $phone, int $level): array
    {
        $user = User::create([
            'name' => $name,
            'phone' => $phone,
            'email' => $phone.'@club.test',
            'password' => 'password',
        ]);
        $pc = $this->pc('PC-'.$phone);
        $booking = $this->seat($user, $pc);
        $identity = FaceitIdentity::query()->create([
            'user_id' => $user->id,
            'faceit_player_id' => 'pid-'.$phone,
            'nickname' => $name,
            'skill_level' => $level,
            'elo' => 1000 + ($level * 100),
            'game_id' => 'cs2',
            'synced_at' => now(),
        ]);

        return compact('user', 'pc', 'booking', 'identity');
    }

    private function pc(string $name): Computer
    {
        return Computer::create([
            'club_id' => $this->club->id,
            'name' => $name,
            'status' => 'busy',
            'kind' => 'pc',
        ]);
    }

    private function seat(User $user, Computer $pc): Booking
    {
        $start = CarbonImmutable::now()->subMinutes(5);
        $end = CarbonImmutable::now()->addHour();
        $local = $start->timezone(config('app.timezone'));

        return Booking::create([
            'user_id' => $user->id,
            'computer_id' => $pc->id,
            'pc_ids' => [(string) $pc->id],
            'date' => $local->toDateString(),
            'start_time' => $local->hour + ($local->minute / 60),
            'duration' => 1,
            'price' => 200,
            'price_minor' => 20000,
            'status' => 'active',
            'pin_code' => substr($user->phone, -4),
            'starts_at' => $start,
            'ends_at' => $end,
            'actual_started_at' => $start,
        ]);
    }
}
