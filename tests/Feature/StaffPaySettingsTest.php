<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffPaySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_page_exposes_the_shift_formula(): void
    {
        $supervisor = $this->makeAdmin('supervisor');

        $this->actingAs($supervisor, 'admin')
            ->get('/admin/staff')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pay_settings.official', fn ($value) => $this->money($value) === '6182.40')
                ->where('pay_settings.hourly', fn ($value) => $this->money($value) === '241.50')
                ->where('pay_settings.day_pay', fn ($value) => $this->money($value) === '3864.00')
                ->where('pay_settings.night_pay', fn ($value) => $this->money($value) === '2318.40')
                ->where('pay_settings.roles', function ($roles) {
                    $byRole = collect($roles)->keyBy('role');

                    return $this->money($byRole['assembler']['shift_rate']) === '2200.00'
                        && $this->money($byRole['senior_manager']['shift_rate']) === '3500.00'
                        && $byRole['assembler']['group'] === 'store'
                        && $byRole['admin']['group'] === 'club';
                }));
    }

    public function test_rate_below_mrot_is_rejected_and_does_not_change_staff(): void
    {
        $supervisor = $this->makeAdmin('supervisor');
        $assembler = $this->makeAdmin('assembler', 2200);

        $this->actingAs($supervisor, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/staff')
            ->post('/admin/staff/pay-settings', [
                'role' => 'assembler',
                'shift_rate' => 6182,
            ])
            ->assertRedirect('/admin/staff')
            ->assertSessionHasErrors('shift_rate');

        $this->assertDatabaseCount('staff_role_rates', 0);
        $this->assertSame('2200.00', $this->money($assembler->fresh()->base_rate));
    }

    public function test_valid_rate_is_saved_and_applied_to_working_staff(): void
    {
        $supervisor = $this->makeAdmin('supervisor');
        $assembler = $this->makeAdmin('assembler', 2200);
        $fired = $this->makeAdmin('assembler', 2200);
        $fired->update(['fired_at' => now(), 'fired_by' => $supervisor->id]);
        $senior = $this->makeAdmin('senior_manager', 3500, 'monthly');

        $this->actingAs($supervisor, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/staff')
            ->post('/admin/staff/pay-settings', [
                'role' => 'assembler',
                'shift_rate' => 8000,
            ])
            ->assertRedirect('/admin/staff')
            ->assertSessionHas('success');

        $this->assertSame('8000.00', $this->money($assembler->fresh()->base_rate));
        $this->assertSame('shift', $assembler->fresh()->pay_type);
        $this->assertSame('2200.00', $this->money($fired->fresh()->base_rate));
        $this->assertSame('8000.00', $this->money((float) Admin::defaultRateFor('assembler')));

        $this->actingAs($supervisor, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/staff')
            ->post('/admin/staff/pay-settings', [
                'role' => 'senior_manager',
                'shift_rate' => 9000,
            ])
            ->assertRedirect('/admin/staff')
            ->assertSessionHas('success');

        $senior->refresh();
        $this->assertSame('9000.00', $this->money($senior->base_rate));
        $this->assertSame('shift', $senior->pay_type);
    }

    public function test_store_staff_cannot_change_pay_settings(): void
    {
        $assembler = $this->makeAdmin('assembler', 2200);

        $this->actingAs($assembler, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/staff/pay-settings', [
                'role' => 'assembler',
                'shift_rate' => 8000,
            ])
            ->assertForbidden();
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function makeAdmin(string $role, ?float $rate = 3000, string $payType = 'shift'): Admin
    {
        $club = Club::query()->first() ?? Club::query()->create([
            'name' => 'Pay Club',
            'slug' => 'pay-club',
            'type' => 'both',
        ]);

        return Admin::query()->create([
            'name' => $role.' '.uniqid(),
            'email' => $role.'.'.uniqid().'@pay.test',
            'password' => 'password',
            'role' => $role,
            'base_rate' => $rate,
            'pay_type' => $payType,
            'club_id' => $club->id,
            'employment_pending' => false,
        ]);
    }
}
