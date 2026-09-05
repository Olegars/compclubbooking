<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ShiftSlot;
use App\Models\ShiftSlotBooking;
use App\Models\ShiftSlotTemplate;
use App\Services\ShiftSlotService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftSlotBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_salary_page_shows_calendar_and_inactive_admin_can_book_free_slot(): void
    {
        $admin = $this->makeAdmin('admin');
        $slot = $this->firstBookableSlot($admin);

        $this->actingAs($admin, 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Salary')
                ->has('calendar.days')
                ->where('calendar.cancel_before_hours', 48)
                ->where('calendar.shift_hours', 12)
            );

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/salary')
            ->post("/admin/salary/slots/{$slot['id']}/book")
            ->assertRedirect('/admin/salary');

        $this->assertDatabaseHas('shift_slot_bookings', [
            'shift_slot_id' => $slot['id'],
            'admin_id' => $admin->id,
            'kind' => ShiftSlotBooking::KIND_LEAD,
            'status' => ShiftSlotBooking::STATUS_BOOKED,
        ]);
    }

    public function test_second_admin_cannot_take_occupied_lead_slot(): void
    {
        $first = $this->makeAdmin('admin');
        $second = $this->makeAdmin('admin');
        $slot = $this->firstBookableSlot($first);

        app(ShiftSlotService::class)->book($first, ShiftSlot::query()->findOrFail($slot['id']));

        $this->actingAs($second, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/salary')
            ->post("/admin/salary/slots/{$slot['id']}/book")
            ->assertRedirect('/admin/salary')
            ->assertSessionHasErrors('message');

        $this->assertSame(1, ShiftSlotBooking::query()->where('shift_slot_id', $slot['id'])->where('status', 'booked')->count());
    }

    public function test_intern_takes_intern_seat_on_occupied_lead_slot(): void
    {
        $lead = $this->makeAdmin('admin');
        $intern = $this->makeAdmin('intern', 1500);
        $slot = $this->firstBookableSlot($lead);
        $model = ShiftSlot::query()->findOrFail($slot['id']);

        app(ShiftSlotService::class)->book($lead, $model);
        app(ShiftSlotService::class)->book($intern, $model->fresh());

        $this->assertDatabaseHas('shift_slot_bookings', [
            'shift_slot_id' => $model->id,
            'admin_id' => $intern->id,
            'kind' => ShiftSlotBooking::KIND_INTERN,
            'status' => ShiftSlotBooking::STATUS_BOOKED,
        ]);
    }

    public function test_second_intern_cannot_take_full_intern_seat(): void
    {
        $lead = $this->makeAdmin('admin');
        $intern = $this->makeAdmin('intern', 1500);
        $other = $this->makeAdmin('intern', 1500);
        $slot = $this->firstBookableSlot($lead);
        $model = ShiftSlot::query()->findOrFail($slot['id']);

        app(ShiftSlotService::class)->book($lead, $model);
        app(ShiftSlotService::class)->book($intern, $model->fresh());

        $this->actingAs($other, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/salary')
            ->post("/admin/salary/slots/{$model->id}/book")
            ->assertRedirect('/admin/salary')
            ->assertSessionHasErrors('message');
    }

    public function test_cancel_is_allowed_48_hours_before_start(): void
    {
        $admin = $this->makeAdmin('admin');
        $slot = $this->makeSlot(now()->addHours(72));
        $booking = app(ShiftSlotService::class)->book($admin, $slot);

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/salary')
            ->post("/admin/salary/slots/{$booking->id}/cancel")
            ->assertRedirect('/admin/salary');

        $this->assertDatabaseHas('shift_slot_bookings', [
            'id' => $booking->id,
            'status' => ShiftSlotBooking::STATUS_CANCELLED,
        ]);
    }

    public function test_cannot_cancel_later_than_48_hours_before_start(): void
    {
        $admin = $this->makeAdmin('admin');
        $slot = $this->makeSlot(now()->addHours(24));
        $booking = app(ShiftSlotService::class)->book($admin, $slot);

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/salary')
            ->post("/admin/salary/slots/{$booking->id}/cancel")
            ->assertRedirect('/admin/salary')
            ->assertSessionHasErrors('message');

        $this->assertDatabaseHas('shift_slot_bookings', [
            'id' => $booking->id,
            'status' => ShiftSlotBooking::STATUS_BOOKED,
        ]);
    }

    public function test_owner_can_switch_calendar_to_24_hour_shifts(): void
    {
        $owner = $this->makeAdmin('owner');
        $admin = $this->makeAdmin('admin');
        $slot12 = $this->firstBookableSlot($admin);
        $this->assertSame(12, $slot12['duration_hours']);

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/salary')
            ->post('/admin/salary/shift-model', ['hours' => 24])
            ->assertRedirect('/admin/salary');

        $slot24 = $this->firstBookableSlot($admin);
        $this->assertSame(24, $slot24['duration_hours']);
        $this->assertSame(24, app(ShiftSlotService::class)->calendar($admin, now()->addMonth()->format('Y-m'))['shift_hours']);
    }

    public function test_admin_cannot_change_shift_model(): void
    {
        $admin = $this->makeAdmin('admin');

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/salary/shift-model', ['hours' => 24])
            ->assertForbidden();
    }

    public function test_booked_12h_slot_survives_switch_to_24h(): void
    {
        $owner = $this->makeAdmin('owner');
        $admin = $this->makeAdmin('admin');
        $slot = $this->firstBookableSlot($admin);
        app(ShiftSlotService::class)->book($admin, ShiftSlot::query()->findOrFail($slot['id']));

        app(ShiftSlotService::class)->setHours($owner, 24);

        $this->assertDatabaseHas('shift_slot_bookings', [
            'shift_slot_id' => $slot['id'],
            'admin_id' => $admin->id,
            'status' => ShiftSlotBooking::STATUS_BOOKED,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function firstBookableSlot(Admin $admin): array
    {
        $days = app(ShiftSlotService::class)->calendar($admin, now()->addMonth()->format('Y-m'))['days'];
        foreach ($days as $day) {
            foreach ($day['slots'] as $slot) {
                if ($slot['can_book']) {
                    return $slot;
                }
            }
        }

        $this->fail('Нет свободного слота в календаре.');
    }

    private function makeSlot($startsAt): ShiftSlot
    {
        $template = ShiftSlotTemplate::query()->firstOrCreate(
            ['club_id' => null, 'name' => 'Тест'],
            [
                'starts_time' => '10:00:00',
                'duration_hours' => 12,
                'intern_capacity' => 1,
                'is_active' => true,
            ]
        );

        return ShiftSlot::query()->create([
            'club_id' => null,
            'template_id' => $template->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(12),
            'intern_capacity' => 1,
        ]);
    }

    private function makeAdmin(string $role, ?float $rate = 2000): Admin
    {
        return Admin::create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.'.'.uniqid().'@slots.test',
            'password' => 'password',
            'role' => $role,
            'base_rate' => $rate,
            'pay_type' => 'shift',
        ]);
    }
}
