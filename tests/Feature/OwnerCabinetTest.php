<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Models\Shift;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerCabinetTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_opens_own_cabinet_not_staff_salary(): void
    {
        $owner = $this->makeAdmin('owner');
        Club::query()->create([
            'name' => '0451',
            'slug' => 'club-'.uniqid(),
            'type' => 'both',
        ]);

        $this->actingAs($owner, 'admin')
            ->get('/admin/cabinet')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/OwnerCabinet')
                ->has('shift')
                ->has('alerts')
                ->has('today.taxable')
                ->where('staff_count', 0)
                ->where('pending_hires', 0)
            );

        $this->actingAs($owner, 'admin')
            ->get('/admin/salary')
            ->assertRedirect('/admin/cabinet');
    }

    public function test_floor_admin_cannot_open_owner_cabinet(): void
    {
        $admin = $this->makeAdmin('admin');

        $this->actingAs($admin, 'admin')
            ->get('/admin/cabinet')
            ->assertRedirect('/admin/salary');

        Shift::query()->create([
            'admin_id' => $admin->id,
            'status' => 'open',
            'started_at' => now()->subHour(),
            'cash_start' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/cabinet')
            ->assertForbidden();

        $this->actingAs($admin, 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Salary'));
    }

    public function test_supervisor_cannot_open_owner_cabinet(): void
    {
        $super = $this->makeAdmin('supervisor');

        $this->actingAs($super, 'admin')
            ->get('/admin/cabinet')
            ->assertForbidden();

        $this->actingAs($super, 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Salary'));
    }

    public function test_intern_is_sent_to_staff_cabinet(): void
    {
        $intern = $this->makeAdmin('intern');

        $this->actingAs($intern, 'admin')
            ->get('/admin/cabinet')
            ->assertRedirect('/admin/salary');
    }

    public function test_store_staff_cannot_open_owner_cabinet(): void
    {
        $club = Club::query()->create([
            'name' => 'Store',
            'slug' => 'store-'.uniqid(),
            'type' => 'store',
        ]);
        $staff = Admin::query()->create([
            'name' => 'Сборщик',
            'email' => 'asm.'.uniqid().'@store.test',
            'password' => 'password',
            'role' => 'assembler',
            'club_id' => $club->id,
            'base_rate' => 2200,
            'pay_type' => 'shift',
        ]);

        $this->actingAs($staff, 'admin')
            ->get('/admin/cabinet')
            ->assertForbidden();

        $this->actingAs($staff, 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Salary'));
    }

    public function test_owner_cannot_withdraw_or_book_staff_slots(): void
    {
        $owner = $this->makeAdmin('owner');

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/salary/withdraw', ['amount' => 100])
            ->assertForbidden();
    }

    private function makeAdmin(string $role): Admin
    {
        return Admin::query()->create([
            'name' => ucfirst($role),
            'email' => $role.'.'.uniqid().'@cabinet.test',
            'password' => 'password',
            'role' => $role,
        ]);
    }
}
