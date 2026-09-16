<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ArenaDuel;
use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ClubFeatureService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ArenaDuelsTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->club = Club::create(['name' => 'Arena Club', 'slug' => 'arena-club']);
    }

    public function test_create_holds_deposit_and_accept_settles_on_match_win(): void
    {
        $a = $this->player('FrostFox', '79001113001', 800);
        $b = $this->player('Rival', '79001113002', 800);
        $pcA = $this->pc('PC-08');
        $pcB = $this->pc('PC-14');
        $bookA = $this->activeSeat($a, $pcA);
        $bookB = $this->activeSeat($b, $pcB);

        $create = $this->postJson('/api/shell/arena/challenges', [
            'terminal_id' => $pcA->id,
            'booking_id' => $bookA->id,
            'game' => 'cs2',
            'mode' => '1v1_aim',
            'entry_fee' => 250,
            'scope' => 'computer',
            'target_computer_id' => $pcB->id,
        ]);
        $create->assertOk()->assertJsonPath('status', 'success');
        $uuid = $create->json('duel.uuid');
        $this->assertNotEmpty($uuid);
        $this->assertEquals(550.0, (float) $a->fresh()->availableBalance());
        $this->assertEquals(800.0, (float) $b->fresh()->availableBalance());

        $this->postJson('/api/shell/arena/challenges/'.$uuid.'/accept', [
            'terminal_id' => $pcB->id,
            'booking_id' => $bookB->id,
        ])->assertOk()->assertJsonPath('status', 'success');

        $this->assertEquals(550.0, (float) $b->fresh()->availableBalance());
        $this->assertSame(ArenaDuel::STATUS_ACCEPTED, ArenaDuel::query()->first()->status);

        $this->postJson('/api/shell/gsi', [
            'terminal_id' => $pcB->id,
            'event' => 'heartbeat',
            'game' => 'cs2',
            'in_match' => true,
        ])->assertOk();
        $this->postJson('/api/shell/gsi', [
            'terminal_id' => $pcA->id,
            'event' => 'heartbeat',
            'game' => 'cs2',
            'in_match' => true,
        ])->assertOk();

        $win = $this->postJson('/api/shell/gsi', [
            'terminal_id' => $pcA->id,
            'event' => 'match_win',
            'game' => 'cs2',
            'in_match' => true,
            'match_id' => 'aim-1',
        ]);
        $win->assertOk()->assertJsonPath('arena_settled.prize', 450);
        $this->assertEquals(1000.0, (float) $a->fresh()->availableBalance());
        $this->assertEquals(550.0, (float) $b->fresh()->availableBalance());
        $this->assertSame(ArenaDuel::STATUS_COMPLETED, ArenaDuel::query()->first()->status);
    }

    public function test_cancel_refunds_creator(): void
    {
        $a = $this->player('A', '79001113011', 400);
        $b = $this->player('B', '79001113012', 400);
        $pcA = $this->pc('PC-01');
        $pcB = $this->pc('PC-02');
        $bookA = $this->activeSeat($a, $pcA);
        $this->activeSeat($b, $pcB);

        $uuid = $this->postJson('/api/shell/arena/challenges', [
            'terminal_id' => $pcA->id,
            'booking_id' => $bookA->id,
            'mode' => '1v1_aim',
            'entry_fee' => 100,
            'scope' => 'hall',
        ])->assertOk()->json('duel.uuid');

        $this->postJson('/api/shell/arena/challenges/'.$uuid.'/cancel', [
            'terminal_id' => $pcA->id,
            'booking_id' => $bookA->id,
        ])->assertOk();

        $this->assertEquals(400.0, (float) $a->fresh()->availableBalance());
        $this->assertSame(ArenaDuel::STATUS_CANCELLED, ArenaDuel::query()->first()->status);
    }

    public function test_feature_off_rejects_create(): void
    {
        app(ClubFeatureService::class)->save($this->club->id, 'arena_duels', false);
        $a = $this->player('Off', '79001113021', 400);
        $pc = $this->pc('PC-OFF');
        $book = $this->activeSeat($a, $pc);

        $this->postJson('/api/shell/arena/challenges', [
            'terminal_id' => $pc->id,
            'booking_id' => $book->id,
            'mode' => '1v1_aim',
            'entry_fee' => 100,
            'scope' => 'hall',
        ])->assertStatus(422)->assertJsonPath('message', 'Арена выключена');
    }

    public function test_insufficient_deposit_rejected(): void
    {
        $a = $this->player('Poor', '79001113031', 40);
        $pc = $this->pc('PC-POOR');
        $book = $this->activeSeat($a, $pc);

        $this->postJson('/api/shell/arena/challenges', [
            'terminal_id' => $pc->id,
            'booking_id' => $book->id,
            'mode' => '1v1_aim',
            'entry_fee' => 100,
            'scope' => 'hall',
        ])->assertStatus(422)->assertJsonPath('message', 'Недостаточно депозита на взнос');
    }

    public function test_admin_force_refund_returns_escrow(): void
    {
        $a = $this->player('Af', '79001113041', 300);
        $b = $this->player('Bf', '79001113042', 300);
        $pcA = $this->pc('PC-41');
        $pcB = $this->pc('PC-42');
        $bookA = $this->activeSeat($a, $pcA);
        $bookB = $this->activeSeat($b, $pcB);
        $uuid = $this->postJson('/api/shell/arena/challenges', [
            'terminal_id' => $pcA->id,
            'booking_id' => $bookA->id,
            'mode' => '1v1_aim',
            'entry_fee' => 100,
            'scope' => 'computer',
            'target_computer_id' => $pcB->id,
        ])->json('duel.uuid');
        $this->postJson('/api/shell/arena/challenges/'.$uuid.'/accept', [
            'terminal_id' => $pcB->id,
            'booking_id' => $bookB->id,
        ])->assertOk();
        $id = ArenaDuel::query()->first()->id;

        $admin = Admin::query()->create([
            'name' => 'Sup',
            'email' => 'arena.sup@test',
            'password' => 'password',
            'role' => 'supervisor',
            'club_id' => $this->club->id,
            'base_rate' => 2000,
            'pay_type' => 'shift',
        ]);
        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/arena/duels/'.$id.'/force-refund')
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertEquals(300.0, (float) $a->fresh()->availableBalance());
        $this->assertEquals(300.0, (float) $b->fresh()->availableBalance());
    }

    public function test_cabinet_lists_open_challenge(): void
    {
        $a = $this->player('CabA', '79001113051', 500);
        $b = $this->player('CabB', '79001113052', 500);
        $pcA = $this->pc('PC-51');
        $pcB = $this->pc('PC-52');
        $bookA = $this->activeSeat($a, $pcA);
        $this->activeSeat($b, $pcB);
        $this->postJson('/api/shell/arena/challenges', [
            'terminal_id' => $pcA->id,
            'booking_id' => $bookA->id,
            'mode' => '1v1_aim',
            'entry_fee' => 100,
            'scope' => 'computer',
            'target_computer_id' => $pcB->id,
        ])->assertOk();

        $this->actingAs($b)->getJson('/account/arena/live')
            ->assertOk()
            ->assertJsonPath('arena.incoming.entry_fee', 100);
    }

    public function test_can_save_arena_settings_from_features_page(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Feat',
            'email' => 'arena.feat@test',
            'password' => 'password',
            'role' => 'supervisor',
            'club_id' => $this->club->id,
            'base_rate' => 2000,
            'pay_type' => 'shift',
        ]);
        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/config/features')
            ->post('/admin/config/features/arena_duels', [
                'enabled' => true,
                'settings' => [
                    'min_entry_fee' => 80,
                    'max_entry_fee' => 2000,
                    'rake_percent' => 8,
                    'invite_seconds' => 45,
                    'open_ttl_minutes' => 7,
                    'rank_delta' => 2,
                    'disconnect_seconds' => 60,
                    'cooldown_seconds' => 90,
                    'first_to_cs' => 6,
                    'print_voucher' => true,
                ],
            ])
            ->assertRedirect();

        $f = app(ClubFeatureService::class);
        $this->assertTrue($f->enabled($this->club->id, 'arena_duels'));
        $this->assertSame(8, $f->int($this->club->id, 'arena_duels', 'rake_percent'));
        $this->assertTrue($f->bool($this->club->id, 'arena_duels', 'print_voucher', false));
    }

    private function player(string $name, string $phone, float $balance): User
    {
        $user = User::create([
            'name' => $name,
            'phone' => $phone,
            'email' => strtolower($name).'@arena.test',
            'password' => 'password',
        ]);
        Wallet::create([
            'user_id' => $user->id,
            'deposit_balance' => $balance,
            'bonus_balance' => 0,
        ]);

        return $user;
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

    private function activeSeat(User $user, Computer $pc): Booking
    {
        $start = CarbonImmutable::now()->subMinutes(10);
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
            'pin_code' => '1111',
            'starts_at' => $start,
            'ends_at' => $end,
            'actual_started_at' => $start,
        ]);
    }
}
