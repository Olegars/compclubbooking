<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\Club;
use App\Models\Computer;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForceReleaseComputerTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    private Computer $computer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->club = Club::create([
            'name' => 'Release Club',
            'slug' => 'release-club',
        ]);

        $this->computer = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'PC-12',
            'status' => 'busy',
        ]);

        $this->user = User::create([
            'name' => 'Stuck Guest',
            'phone' => '+79991112233',
            'email' => 'stuck@example.test',
            'password' => 'password',
        ]);
    }

    public function test_owner_closes_stuck_active_session(): void
    {
        $booking = $this->makeActiveBooking();
        $owner = $this->makeAdmin('owner');

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/computers/release', [
                'computer_id' => $this->computer->id,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('had_session', true)
            ->assertJsonPath('booking_id', $booking->id);

        $booking->refresh();
        $this->assertSame('completed', $booking->status);
        $this->assertNotNull($booking->actual_ended_at);

        $this->computer->refresh();
        $this->assertSame('available', $this->computer->status);
    }

    public function test_admin_cannot_release_computer(): void
    {
        $this->makeActiveBooking();
        $admin = $this->makeAdmin('admin');

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/computers/release', [
                'computer_id' => $this->computer->id,
            ])
            ->assertForbidden();

        $this->assertSame('active', Booking::query()->where('computer_id', $this->computer->id)->value('status'));
    }

    public function test_guest_cannot_release_computer(): void
    {
        $this->postJson('/admin/api/computers/release', [
            'computer_id' => $this->computer->id,
        ])->assertRedirect();
    }

    private function makeAdmin(string $role): Admin
    {
        return Admin::create([
            'name' => ucfirst($role),
            'email' => $role.'@release.test',
            'password' => 'password',
            'role' => $role,
            'club_id' => $this->club->id,
        ]);
    }

    private function makeActiveBooking(): Booking
    {
        $startsAt = now()->subHour();
        $endsAt = now()->addHour();

        $group = BookingGroup::create([
            'user_id' => $this->user->id,
            'club_id' => $this->club->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => 'active',
            'payment_status' => 'paid',
            'currency' => 'RUB',
            'computers_total_minor' => 10000,
            'games_total_minor' => 0,
            'total_minor' => 10000,
            'paid_total_minor' => 10000,
            'paid_at' => $startsAt->copy()->subDay(),
        ]);

        return Booking::create([
            'booking_group_id' => $group->id,
            'user_id' => $this->user->id,
            'computer_id' => $this->computer->id,
            'pc_ids' => [(string) $this->computer->id],
            'date' => $startsAt->toDateString(),
            'start_time' => $startsAt->hour + ($startsAt->minute / 60),
            'duration' => 2,
            'price' => 100,
            'price_minor' => 10000,
            'status' => 'active',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'actual_started_at' => $startsAt,
        ]);
    }
}
