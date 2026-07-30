<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\Club;
use App\Models\Computer;
use App\Models\User;
use App\Services\BookingSessionTimingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BookingSessionTimingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Club $club;

    private Computer $computer;

    private BookingSessionTimingService $timing;

    protected function setUp(): void
    {
        parent::setUp();

        config(['club.booking.late_start_grace_minutes' => 15]);

        $this->user = User::create([
            'name' => 'Session Tester',
            'phone' => '+79990000099',
            'email' => 'session@example.test',
            'password' => 'password',
        ]);

        $this->club = Club::create([
            'name' => 'Test Club',
            'slug' => 'session-club',
        ]);

        $this->computer = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-1',
            'status' => 'available',
        ]);

        $this->timing = app(BookingSessionTimingService::class);
    }

    public function test_early_start_shifts_ends_at_to_preserve_paid_duration(): void
    {
        $startsAt = CarbonImmutable::parse('2026-08-01 12:00:00', config('app.timezone'));
        $endsAt = $startsAt->addHours(2);
        $now = $startsAt->subMinutes(10);

        $booking = $this->makeBooking($startsAt, $endsAt);

        $result = $this->timing->activate($booking, $now);

        $this->assertSame(120, $result['time_remaining_minutes']);
        $booking->refresh();
        $this->assertSame('active', $booking->status);
        $this->assertTrue($booking->actual_started_at->equalTo($now));
        $this->assertTrue($booking->starts_at->equalTo($now));
        $this->assertTrue($booking->ends_at->equalTo($now->addMinutes(120)));

        $group = $booking->group()->first();
        $this->assertSame('active', $group->status);
        $this->assertTrue($group->ends_at->equalTo($now->addMinutes(120)));
    }

    public function test_early_start_blocked_when_computer_occupied(): void
    {
        $startsAt = CarbonImmutable::parse('2026-08-01 12:00:00', config('app.timezone'));
        $endsAt = $startsAt->addHour();
        $now = $startsAt->subMinutes(30);

        $otherUser = User::create([
            'name' => 'Other',
            'phone' => '+79990000098',
            'email' => 'other@example.test',
            'password' => 'password',
        ]);

        Booking::create([
            'user_id' => $otherUser->id,
            'computer_id' => $this->computer->id,
            'pc_ids' => [(string) $this->computer->id],
            'date' => $now->toDateString(),
            'start_time' => 11.0,
            'duration' => 1,
            'price' => 100,
            'status' => 'active',
            'starts_at' => $now->subMinutes(15),
            'ends_at' => $startsAt,
        ]);

        $booking = $this->makeBooking($startsAt, $endsAt);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Компьютер ещё занят');
        $this->timing->activate($booking, $now);
    }

    public function test_late_within_grace_keeps_original_ends_at(): void
    {
        $startsAt = CarbonImmutable::parse('2026-08-01 12:00:00', config('app.timezone'));
        $endsAt = $startsAt->addHours(2);
        $now = $startsAt->addMinutes(10);

        $booking = $this->makeBooking($startsAt, $endsAt);
        $result = $this->timing->activate($booking, $now);

        $this->assertSame(110, $result['time_remaining_minutes']);
        $booking->refresh();
        $this->assertSame('active', $booking->status);
        $this->assertTrue($booking->ends_at->equalTo($endsAt));
        $this->assertTrue($booking->starts_at->equalTo($startsAt));
    }

    public function test_late_past_grace_cancels_as_no_show(): void
    {
        $startsAt = CarbonImmutable::parse('2026-08-01 12:00:00', config('app.timezone'));
        $endsAt = $startsAt->addHours(2);
        $now = $startsAt->addMinutes(16);

        $booking = $this->makeBooking($startsAt, $endsAt);

        try {
            $this->timing->activate($booking, $now);
            $this->fail('Expected no-show exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('опоздания', $e->getMessage());
        }

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
        $this->assertNull($booking->pin_code);
        $this->assertSame('cancelled', $booking->group()->first()->status);
    }

    public function test_cancel_no_shows_releases_slot_after_grace(): void
    {
        $startsAt = CarbonImmutable::parse('2026-08-01 12:00:00', config('app.timezone'));
        $endsAt = $startsAt->addHours(2);
        $booking = $this->makeBooking($startsAt, $endsAt);

        $now = $startsAt->addMinutes(16);
        $count = $this->timing->cancelNoShows($now);

        $this->assertSame(1, $count);
        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
        $this->assertSame('cancelled', $booking->group()->first()->status);
    }

    public function test_shell_login_early_start_returns_full_paid_duration(): void
    {
        $startsAt = CarbonImmutable::parse('2026-08-01 12:00:00', config('app.timezone'));
        $endsAt = $startsAt->addHour();
        $now = $startsAt->subMinutes(20);
        CarbonImmutable::setTestNow($now);

        $booking = $this->makeBooking($startsAt, $endsAt, '4321');

        $this->postJson('/api/shell/login', [
            'phone' => $this->user->phone,
            'pin' => '4321',
            'terminal_id' => $this->computer->id,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('user.time_remaining', '01:00:00');

        $booking->refresh();
        $this->assertSame('active', $booking->status);
        $this->assertTrue($booking->ends_at->equalTo($now->addHour()));

        CarbonImmutable::setTestNow();
    }

    private function makeBooking(
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        string $pin = '1234'
    ): Booking {
        $local = $startsAt->timezone(config('app.timezone'));
        $durationHours = $startsAt->diffInMinutes($endsAt) / 60;

        $group = BookingGroup::create([
            'user_id' => $this->user->id,
            'club_id' => $this->club->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'currency' => 'RUB',
            'computers_total_minor' => 10000,
            'games_total_minor' => 0,
            'total_minor' => 10000,
            'paid_total_minor' => 10000,
            'paid_at' => $startsAt->subDay(),
        ]);

        return Booking::create([
            'booking_group_id' => $group->id,
            'user_id' => $this->user->id,
            'computer_id' => $this->computer->id,
            'pc_ids' => [(string) $this->computer->id],
            'date' => $local->toDateString(),
            'start_time' => $local->hour + ($local->minute / 60),
            'duration' => $durationHours,
            'price' => 100,
            'price_minor' => 10000,
            'status' => 'confirmed',
            'pin_code' => $pin,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }
}
