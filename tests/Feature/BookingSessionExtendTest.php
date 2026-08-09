<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\Space;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Zone;
use App\Services\BookingSessionExtendService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingSessionExtendTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Computer $pc;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Extend User',
            'phone' => '+79995556677',
            'email' => 'extend@example.test',
            'password' => 'password',
            'balance' => 1000,
        ]);
        Wallet::create([
            'user_id' => $this->user->id,
            'deposit_balance' => 1000,
            'bonus_balance' => 0,
        ]);

        $club = Club::create(['name' => 'Club', 'slug' => 'extend-club']);
        $zone = Zone::create(['name' => 'Standard', 'slug' => 'std-ext', 'color' => '#22c55e']);
        $space = Space::create([
            'club_id' => $club->id,
            'zone_id' => $zone->id,
            'name' => 'Room',
            'surcharge_per_hour' => 0,
        ]);
        $this->pc = Computer::create([
            'club_id' => $club->id,
            'space_id' => $space->id,
            'name' => 'TV-EXT',
            'status' => 'busy',
            'kind' => 'pc',
        ]);

        $now = CarbonImmutable::now(config('app.timezone'));
        $this->booking = Booking::create([
            'user_id' => $this->user->id,
            'computer_id' => $this->pc->id,
            'pc_ids' => [(string) $this->pc->id],
            'date' => $now->toDateString(),
            'start_time' => $now->hour + $now->minute / 60,
            'duration' => 1,
            'price' => 200,
            'status' => 'active',
            'starts_at' => $now->subMinutes(10),
            'ends_at' => $now->addHour(),
            'actual_started_at' => $now->subMinutes(10),
        ]);
    }

    public function test_extend_from_balance(): void
    {
        $svc = app(BookingSessionExtendService::class);
        $before = CarbonImmutable::parse($this->booking->ends_at);

        $result = $svc->extend($this->booking, $this->user, 60);

        $this->assertTrue($result['applied'] ?? false);
        $this->booking->refresh();
        $after = CarbonImmutable::parse($this->booking->ends_at);
        $this->assertEqualsWithDelta(60, $before->diffInMinutes($after), 1);

        $this->user->refresh();
        $this->assertLessThan(1000, (float) $this->user->availableBalance());
    }

    public function test_extend_needs_topup_when_broke(): void
    {
        $this->user->wallet->update(['deposit_balance' => 10, 'bonus_balance' => 0]);
        $this->user->update(['balance' => 10]);
        $endsBefore = (string) $this->booking->ends_at;

        $result = app(BookingSessionExtendService::class)
            ->extend($this->booking->fresh(), $this->user->fresh(), 60);

        $this->assertFalse($result['applied'] ?? true);
        $this->assertTrue($result['needs_topup'] ?? false);
        $this->assertGreaterThan(0, $result['shortage'] ?? 0);

        $this->booking->refresh();
        $this->assertSame($endsBefore, (string) $this->booking->ends_at);
    }

    public function test_shell_extend_options_endpoint(): void
    {
        $this->getJson('/api/shell/session/extend/options?terminal_id='.$this->pc->id.'&booking_id='.$this->booking->id)
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['options', 'balance', 'hourly_rate']);
    }
}
