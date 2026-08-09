<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\Space;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Zone;
use App\Services\BookingSeatTransferService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingSeatTransferTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Club $club;

    private Computer $from;

    private Computer $to;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Transfer User',
            'phone' => '+79991112233',
            'email' => 'transfer@example.test',
            'password' => 'password',
            'balance' => 500,
        ]);

        Wallet::create([
            'user_id' => $this->user->id,
            'deposit_balance' => 500,
            'bonus_balance' => 0,
        ]);

        $this->club = Club::create(['name' => 'Club', 'slug' => 'xfer-club']);

        $zoneA = Zone::create(['name' => 'Standard', 'slug' => 'std', 'color' => '#22c55e']);
        $zoneB = Zone::create(['name' => 'VIP', 'slug' => 'vip', 'color' => '#06b6d4']);

        $spaceA = Space::create([
            'club_id' => $this->club->id,
            'zone_id' => $zoneA->id,
            'name' => 'Room A',
            'surcharge_per_hour' => 0,
        ]);
        $spaceB = Space::create([
            'club_id' => $this->club->id,
            'zone_id' => $zoneB->id,
            'name' => 'Room B',
            'surcharge_per_hour' => 0,
        ]);

        $this->from = Computer::create([
            'club_id' => $this->club->id,
            'space_id' => $spaceA->id,
            'name' => 'ПК-01',
            'status' => 'busy',
            'kind' => 'pc',
        ]);
        $this->to = Computer::create([
            'club_id' => $this->club->id,
            'space_id' => $spaceB->id,
            'name' => 'ПК-02',
            'status' => 'available',
            'kind' => 'pc',
        ]);

        $now = CarbonImmutable::now(config('app.timezone'));
        $this->booking = Booking::create([
            'user_id' => $this->user->id,
            'computer_id' => $this->from->id,
            'pc_ids' => [(string) $this->from->id],
            'date' => $now->toDateString(),
            'start_time' => $now->hour + $now->minute / 60,
            'duration' => 2,
            'price' => 200,
            'status' => 'active',
            'pin_code' => '1234',
            'starts_at' => $now->subHour(),
            'ends_at' => $now->addHour(),
            'actual_started_at' => $now->subHour(),
        ]);
    }

    public function test_transfer_moves_booking_and_soft_kick_balance(): void
    {
        // Same default hourly → no charge
        $svc = app(BookingSeatTransferService::class);
        $result = $svc->transfer($this->booking, (int) $this->to->id, $this->user);

        $this->booking->refresh();
        $this->assertSame((int) $this->to->id, (int) $this->booking->computer_id);
        $this->assertContains((string) $this->to->id, $this->booking->pc_ids);
        $this->assertTrue($result['applied'] ?? false);
        $this->assertNotEmpty($result['pin_code'] ?? null);
        $this->assertSame($result['pin_code'], $this->booking->pin_code);

        // Old terminal poll → session not active
        $this->getJson('/api/shell/balance?terminal_id='.$this->from->id.'&booking_id='.$this->booking->id)
            ->assertOk()
            ->assertJsonPath('session_active', false)
            ->assertJsonPath('relocated', true);

        // New terminal → booking already bound (API), but Shell UI needs PIN login
        $this->getJson('/api/shell/balance?terminal_id='.$this->to->id.'&booking_id='.$this->booking->id)
            ->assertOk()
            ->assertJsonPath('session_active', true);

        $pin = (string) $result['pin_code'];
        $this->postJson('/api/shell/login', [
            'phone' => $this->user->phone,
            'pin' => $pin,
            'terminal_id' => $this->to->id,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('booking_id', $this->booking->id);

        $this->booking->refresh();
        $this->assertNull($this->booking->pin_code);
        $this->assertNull($this->booking->transfer_pending_at);
        $this->assertNull($this->booking->transfer_from_computer_id);
    }

    public function test_abandoned_transfer_reclaims_target_after_grace(): void
    {
        $svc = app(BookingSeatTransferService::class);
        $svc->transfer($this->booking, (int) $this->to->id, $this->user);

        $this->booking->refresh();
        $this->from->refresh();
        $this->to->refresh();
        $this->assertSame((int) $this->to->id, (int) $this->booking->computer_id);
        $this->assertSame('available', $this->from->status);
        $this->assertSame('busy', $this->to->status);
        $this->assertNotNull($this->booking->transfer_pending_at);
        $this->assertSame((int) $this->from->id, (int) $this->booking->transfer_from_computer_id);

        // Ещё внутри grace — не откатываем
        $reclaimed = $svc->reclaimAbandonedTransfers(
            CarbonImmutable::now(config('app.timezone'))->addMinutes(5),
            10
        );
        $this->assertSame(0, $reclaimed);
        $this->to->refresh();
        $this->assertSame('busy', $this->to->status);

        // После grace — бронь обратно на исходный ПК, целевой свободен
        $reclaimed = $svc->reclaimAbandonedTransfers(
            CarbonImmutable::now(config('app.timezone'))->addMinutes(11),
            10
        );
        $this->assertSame(1, $reclaimed);

        $this->booking->refresh();
        $this->from->refresh();
        $this->to->refresh();
        $this->assertSame((int) $this->from->id, (int) $this->booking->computer_id);
        $this->assertNull($this->booking->pin_code);
        $this->assertNull($this->booking->transfer_pending_at);
        $this->assertSame('busy', $this->from->status);
        $this->assertSame('available', $this->to->status);
    }

    public function test_expired_abandoned_transfer_frees_target(): void
    {
        $svc = app(BookingSeatTransferService::class);
        $svc->transfer($this->booking, (int) $this->to->id, $this->user);

        $this->booking->refresh();
        $past = CarbonImmutable::parse($this->booking->ends_at)->addMinute();

        $closed = app(\App\Services\BookingSessionTimingService::class)->completeExpiredSessions($past);
        $this->assertGreaterThanOrEqual(1, $closed);

        $this->to->refresh();
        $this->assertSame('available', $this->to->status);
        $this->booking->refresh();
        $this->assertSame('completed', $this->booking->status);
    }

    public function test_shell_targets_endpoint(): void
    {
        $this->getJson('/api/shell/transfer/targets?terminal_id='.$this->from->id)
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonFragment(['id' => $this->to->id]);
    }

    public function test_account_targets_include_map_payload(): void
    {
        $this->actingAs($this->user)
            ->getJson('/account/transfer/targets')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('from_computer_id', $this->from->id)
            ->assertJsonFragment(['id' => $this->to->id])
            ->assertJsonStructure([
                'map_config',
                'computers',
                'occupied_ids',
                'selectable_ids',
                'targets',
            ]);
    }

    public function test_rejects_occupied_target(): void
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        Booking::create([
            'user_id' => $this->user->id,
            'computer_id' => $this->to->id,
            'pc_ids' => [(string) $this->to->id],
            'date' => $now->toDateString(),
            'start_time' => 0,
            'duration' => 2,
            'price' => 100,
            'status' => 'active',
            'pin_code' => '9999',
            'starts_at' => $now->subMinutes(10),
            'ends_at' => $now->addHours(2),
            'actual_started_at' => $now->subMinutes(10),
        ]);

        $this->expectException(\RuntimeException::class);
        app(BookingSeatTransferService::class)->preview($this->booking, (int) $this->to->id);
    }
}
