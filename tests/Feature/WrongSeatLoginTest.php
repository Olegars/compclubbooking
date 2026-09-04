<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\Club;
use App\Models\Computer;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ShellQrLoginService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WrongSeatLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Club $club;

    private Computer $bookedPc;

    private Computer $otherPc;

    protected function setUp(): void
    {
        parent::setUp();

        config(['club.booking.late_start_grace_minutes' => 30]);

        $this->user = User::create([
            'name' => 'Seat User',
            'phone' => '+79991110001',
            'email' => 'seat@example.test',
            'password' => 'password',
            'balance' => 2000,
        ]);
        Wallet::create([
            'user_id' => $this->user->id,
            'deposit_balance' => 2000,
            'bonus_balance' => 0,
        ]);

        $this->club = Club::create(['name' => 'Seat Club', 'slug' => 'seat-club']);
        $this->bookedPc = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'ПК-01',
            'status' => 'available',
            'kind' => 'pc',
        ]);
        $this->otherPc = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'ПК-02',
            'status' => 'available',
            'kind' => 'pc',
        ]);
    }

    public function test_pin_on_free_wrong_pc_asks_to_switch(): void
    {
        $booking = $this->makeUserBooking('1234');

        $this->postJson('/api/shell/login', [
            'phone' => $this->user->phone,
            'pin' => '1234',
            'terminal_id' => $this->otherPc->id,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'wrong_pc_available')
            ->assertJsonPath('booked_computer.name', 'ПК-01')
            ->assertJsonPath('this_computer.name', 'ПК-02');

        $booking->refresh();
        $this->assertSame($this->bookedPc->id, (int) $booking->computer_id);
        $this->assertSame('confirmed', $booking->status);
    }

    public function test_pin_accept_moves_booking_and_opens_session(): void
    {
        $booking = $this->makeUserBooking('1234');

        $this->postJson('/api/shell/login', [
            'phone' => $this->user->phone,
            'pin' => '1234',
            'terminal_id' => $this->otherPc->id,
            'accept_seat_change' => true,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('booking_id', $booking->id);

        $booking->refresh();
        $this->assertSame('active', $booking->status);
        $this->assertSame($this->otherPc->id, (int) $booking->computer_id);
        $this->assertSame([(string) $this->otherPc->id], $booking->pc_ids);
    }

    public function test_pin_on_occupied_wrong_pc_sends_guest_to_booked_seat(): void
    {
        $booking = $this->makeUserBooking('1234', durationHours: 2);
        $this->makeForeignBookingOn(
            $this->otherPc,
            CarbonImmutable::now(config('app.timezone'))->addMinutes(20),
            1
        );

        $this->postJson('/api/shell/login', [
            'phone' => $this->user->phone,
            'pin' => '1234',
            'terminal_id' => $this->otherPc->id,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'wrong_pc_occupied')
            ->assertJsonPath('booked_computer.name', 'ПК-01');

        $booking->refresh();
        $this->assertSame($this->bookedPc->id, (int) $booking->computer_id);
        $this->assertSame('confirmed', $booking->status);
    }

    public function test_wrong_pin_still_generic_error(): void
    {
        $this->makeUserBooking('1234');

        $this->postJson('/api/shell/login', [
            'phone' => $this->user->phone,
            'pin' => '9999',
            'terminal_id' => $this->otherPc->id,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'error');
    }

    public function test_qr_on_free_wrong_pc_asks_to_switch(): void
    {
        $this->makeUserBooking('1234');
        $qr = app(ShellQrLoginService::class);
        $issued = $qr->issue($this->otherPc);

        $result = $qr->redeem($this->user, $issued['token']);
        $this->assertSame('wrong_pc_available', $result['status']);
        $this->assertSame('ПК-01', $result['booked_computer']['name']);
    }

    public function test_qr_accept_moves_booking_and_activates(): void
    {
        $booking = $this->makeUserBooking('1234');
        $qr = app(ShellQrLoginService::class);
        $issued = $qr->issue($this->otherPc);

        $result = $qr->redeem($this->user, $issued['token'], true);
        $this->assertSame('activated', $result['status']);

        $booking->refresh();
        $this->assertSame('active', $booking->status);
        $this->assertSame($this->otherPc->id, (int) $booking->computer_id);
    }

    public function test_qr_on_occupied_wrong_pc_does_not_offer_walk_in(): void
    {
        $this->makeUserBooking('1234', durationHours: 2);
        $this->makeForeignBookingOn(
            $this->otherPc,
            CarbonImmutable::now(config('app.timezone'))->addMinutes(20),
            1
        );
        $qr = app(ShellQrLoginService::class);
        $issued = $qr->issue($this->otherPc);

        $this->actingAs($this->user)
            ->postJson('/account/qr/redeem', ['token' => $issued['token']])
            ->assertOk()
            ->assertJsonPath('status', 'wrong_pc_occupied');
    }

    private function makeUserBooking(string $pin, float $durationHours = 2): Booking
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $ends = $now->addMinutes((int) round($durationHours * 60));

        $group = BookingGroup::create([
            'user_id' => $this->user->id,
            'club_id' => $this->club->id,
            'starts_at' => $now,
            'ends_at' => $ends,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'currency' => 'RUB',
            'computers_total_minor' => 30000,
            'games_total_minor' => 0,
            'total_minor' => 30000,
            'paid_total_minor' => 30000,
            'paid_at' => $now,
        ]);

        return Booking::create([
            'booking_group_id' => $group->id,
            'user_id' => $this->user->id,
            'computer_id' => $this->bookedPc->id,
            'pc_ids' => [(string) $this->bookedPc->id],
            'date' => $now->toDateString(),
            'start_time' => $now->hour + $now->minute / 60 + $now->second / 3600,
            'duration' => $durationHours,
            'price' => 300,
            'price_minor' => 30000,
            'status' => 'confirmed',
            'pin_code' => $pin,
            'starts_at' => $now,
            'ends_at' => $ends,
        ]);
    }

    private function makeForeignBookingOn(Computer $pc, CarbonImmutable $startsAt, float $durationHours): Booking
    {
        $other = User::create([
            'name' => 'Other Guest',
            'phone' => '+79991110002',
            'email' => 'other-seat@example.test',
            'password' => 'password',
        ]);
        $ends = $startsAt->addMinutes((int) round($durationHours * 60));

        $group = BookingGroup::create([
            'user_id' => $other->id,
            'club_id' => $this->club->id,
            'starts_at' => $startsAt,
            'ends_at' => $ends,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'currency' => 'RUB',
            'computers_total_minor' => 10000,
            'games_total_minor' => 0,
            'total_minor' => 10000,
            'paid_total_minor' => 10000,
            'paid_at' => $startsAt->subHour(),
        ]);

        return Booking::create([
            'booking_group_id' => $group->id,
            'user_id' => $other->id,
            'computer_id' => $pc->id,
            'pc_ids' => [(string) $pc->id],
            'date' => $startsAt->toDateString(),
            'start_time' => $startsAt->hour + $startsAt->minute / 60 + $startsAt->second / 3600,
            'duration' => $durationHours,
            'price' => 100,
            'price_minor' => 10000,
            'status' => 'confirmed',
            'pin_code' => '5555',
            'starts_at' => $startsAt,
            'ends_at' => $ends,
        ]);
    }
}
