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

        config(['club.booking.late_start_grace_minutes' => 30]);

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

    public function test_late_within_soft_grace_keeps_full_paid_duration(): void
    {
        $startsAt = CarbonImmutable::parse('2026-08-01 10:00:00', config('app.timezone'));
        $endsAt = $startsAt->addHour();
        $now = $startsAt->addMinutes(20);

        $booking = $this->makeBooking($startsAt, $endsAt);
        $result = $this->timing->activate($booking, $now);

        $this->assertSame(60, $result['time_remaining_minutes']);
        $booking->refresh();
        $this->assertSame('active', $booking->status);
        $this->assertTrue($booking->ends_at->equalTo($now->addMinutes(60)));
    }

    public function test_late_past_soft_grace_deducts_from_billing_start(): void
    {
        // 10:00–11:00, arrive 10:50 → 30 min free wait, 20 min billed → 40 left
        $startsAt = CarbonImmutable::parse('2026-08-01 10:00:00', config('app.timezone'));
        $endsAt = $startsAt->addHour();
        $now = $startsAt->addMinutes(50);

        $booking = $this->makeBooking($startsAt, $endsAt);
        $result = $this->timing->activate($booking, $now);

        $this->assertSame(40, $result['time_remaining_minutes']);
        $this->assertSame(40 * 60, $result['time_remaining_seconds']);
        $booking->refresh();
        $this->assertSame('active', $booking->status);
        $this->assertTrue($booking->ends_at->equalTo($now->addMinutes(40)));

        // Simulate PG timestamptz re-read skew (+3h) while wall fields stay correct.
        $booking->forceFill([
            'starts_at' => $now->addHours(3),
            'ends_at' => $now->addHours(3)->addMinutes(40),
            'actual_started_at' => $now->addHours(3),
        ])->saveQuietly();

        $remaining = $this->timing->remainingSeconds($booking->fresh(), $now);
        $this->assertEqualsWithDelta(40 * 60, $remaining, 5);
    }

    public function test_activate_uses_wall_clock_when_starts_at_is_timezone_skewed(): void
    {
        // Wall: 10:00–11:00. Modern starts_at skewed +3h → без wall выглядело бы как early start.
        $startsAt = CarbonImmutable::parse('2026-08-01 10:00:00', config('app.timezone'));
        $endsAt = $startsAt->addHour();
        $now = $startsAt->addMinutes(50);

        $booking = $this->makeBooking($startsAt, $endsAt);
        $booking->forceFill([
            'starts_at' => $startsAt->addHours(3),
            'ends_at' => $endsAt->addHours(3),
        ])->saveQuietly();

        $result = $this->timing->activate($booking->fresh(), $now);

        // 30 мин grace + 20 мин списания → 40 мин, НЕ полный час (early).
        $this->assertSame(40, $result['time_remaining_minutes']);
        $this->assertSame(40 * 60, $result['time_remaining_seconds']);
    }

    public function test_following_booking_forces_strict_deduction_from_start(): void
    {
        $startsAt = CarbonImmutable::parse('2026-08-01 10:00:00', config('app.timezone'));
        $endsAt = $startsAt->addHour();
        $now = $startsAt->addMinutes(20);

        $this->makeFollowingBooking($endsAt, $endsAt->addHour());

        $booking = $this->makeBooking($startsAt, $endsAt);
        $result = $this->timing->activate($booking, $now);

        // Strict: keep original ends_at → 40 minutes left (11:00 − 10:20)
        $this->assertSame(40, $result['time_remaining_minutes']);
        $booking->refresh();
        $this->assertTrue($booking->ends_at->equalTo($endsAt));
        $this->assertTrue($booking->starts_at->equalTo($startsAt));
    }

    public function test_following_booking_rejects_after_scheduled_end(): void
    {
        $startsAt = CarbonImmutable::parse('2026-08-01 10:00:00', config('app.timezone'));
        $endsAt = $startsAt->addHour();
        $now = $endsAt->addMinutes(5);

        $this->makeFollowingBooking($endsAt, $endsAt->addHour());
        $booking = $this->makeBooking($startsAt, $endsAt);

        try {
            $this->timing->activate($booking, $now);
            $this->fail('Expected expiry exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('закончилось', $e->getMessage());
        }

        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
    }

    public function test_soft_grace_allows_start_past_original_ends_at(): void
    {
        // 10:00–11:00, arrive 11:10 → billed from 10:30 = 40 min → 20 left
        $startsAt = CarbonImmutable::parse('2026-08-01 10:00:00', config('app.timezone'));
        $endsAt = $startsAt->addHour();
        $now = $startsAt->addMinutes(70);

        $booking = $this->makeBooking($startsAt, $endsAt);
        $result = $this->timing->activate($booking, $now);

        $this->assertSame(20, $result['time_remaining_minutes']);
        $booking->refresh();
        $this->assertSame('active', $booking->status);
    }

    public function test_cancel_no_shows_only_after_effective_time_elapsed(): void
    {
        $startsAt = CarbonImmutable::parse('2026-08-01 10:00:00', config('app.timezone'));
        $endsAt = $startsAt->addHour();
        $booking = $this->makeBooking($startsAt, $endsAt);

        // 50 min late — still playable under soft grace
        $count = $this->timing->cancelNoShows($startsAt->addMinutes(50));
        $this->assertSame(0, $count);
        $booking->refresh();
        $this->assertSame('confirmed', $booking->status);

        // starts_at + 30 grace + 60 paid = 11:30
        $count = $this->timing->cancelNoShows($startsAt->addMinutes(90));
        $this->assertSame(1, $count);
        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
        $this->assertSame('cancelled', $booking->group()->first()->status);
    }

    public function test_cancel_no_shows_finds_booking_when_starts_at_is_timezone_skewed(): void
    {
        $startsAt = CarbonImmutable::parse('2026-08-01 10:00:00', config('app.timezone'));
        $endsAt = $startsAt->addHour();
        $booking = $this->makeBooking($startsAt, $endsAt);

        // starts_at skewed +3h into the future — old query never candidates this row.
        $booking->forceFill([
            'starts_at' => $startsAt->addHours(3),
            'ends_at' => $endsAt->addHours(3),
        ])->saveQuietly();

        $count = $this->timing->cancelNoShows($startsAt->addMinutes(90));
        $this->assertSame(1, $count);
        $booking->refresh();
        $this->assertSame('cancelled', $booking->status);
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

    public function test_complete_expired_sessions_closes_active_past_ends_at(): void
    {
        $now = CarbonImmutable::parse('2026-07-31 00:30:00', 'Europe/Moscow');
        CarbonImmutable::setTestNow($now);
        $this->travelTo($now);

        $booking = $this->makeBooking(
            $now->subHour(),
            $now->subMinutes(10),
            '1111'
        );
        $booking->update([
            'status' => 'active',
            'actual_started_at' => $now->subHour(),
        ]);

        $closed = $this->timing->completeExpiredSessions($now);

        $this->assertSame(1, $closed);
        $booking->refresh();
        $this->assertSame('completed', $booking->status);
        $this->assertNotNull($booking->actual_ended_at);

        CarbonImmutable::setTestNow();
    }

    public function test_complete_expired_does_not_close_unstarted_within_soft_grace(): void
    {
        $startsAt = CarbonImmutable::parse('2026-08-01 10:00:00', config('app.timezone'));
        $endsAt = $startsAt->addHour();
        $booking = $this->makeBooking($startsAt, $endsAt);

        // Past card end, but soft grace still playable until 11:30
        $closed = $this->timing->completeExpiredSessions($startsAt->addMinutes(70));

        $this->assertSame(0, $closed);
        $booking->refresh();
        $this->assertSame('confirmed', $booking->status);
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

    private function makeFollowingBooking(CarbonImmutable $startsAt, CarbonImmutable $endsAt): Booking
    {
        $otherUser = User::create([
            'name' => 'Next Guest',
            'phone' => '+79990000097',
            'email' => 'next@example.test',
            'password' => 'password',
        ]);

        $local = $startsAt->timezone(config('app.timezone'));
        $durationHours = $startsAt->diffInMinutes($endsAt) / 60;

        $group = BookingGroup::create([
            'user_id' => $otherUser->id,
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
            'user_id' => $otherUser->id,
            'computer_id' => $this->computer->id,
            'pc_ids' => [(string) $this->computer->id],
            'date' => $local->toDateString(),
            'start_time' => $local->hour + ($local->minute / 60),
            'duration' => $durationHours,
            'price' => 100,
            'price_minor' => 10000,
            'status' => 'confirmed',
            'pin_code' => '9999',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }
}
