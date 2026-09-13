<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Models\StaffEmploymentProfile;
use App\Support\StaffEmploymentRules;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreStaffAuthTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = Club::query()->create([
            'name' => 'PC Store',
            'slug' => 'pc-store-'.uniqid(),
            'type' => 'store',
        ]);
    }

    public function test_store_login_page_is_public(): void
    {
        $this->get('/store/login')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/StoreLogin'));
        $this->get('/store')->assertRedirect('/store/login');
    }

    public function test_store_register_opens_hire_page_as_assembler(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/store/register', [
                'name' => 'Пётр Сборщик',
                'email' => 'builder@store.test',
                'password' => 'secret12',
                'password_confirmation' => 'secret12',
                'role' => 'assembler',
            ])
            ->assertRedirect('/store/hire');

        $this->assertDatabaseHas('admins', [
            'email' => 'builder@store.test',
            'role' => 'assembler',
            'employment_pending' => true,
            'club_id' => $this->club->id,
        ]);

        $admin = Admin::query()->where('email', 'builder@store.test')->first();
        $this->actingAs($admin, 'admin')
            ->get('/store/hire')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/StoreHire')
                ->where('employment.required', true)
                ->where('employment.status', 'draft')
            );
    }

    public function test_store_staff_cannot_login_on_club_page(): void
    {
        $assembler = $this->makeAssembler();

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/login')
            ->post('/admin/login', [
                'email' => $assembler->email,
                'password' => 'password',
            ])
            ->assertRedirect('/admin/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest('admin');
    }

    public function test_club_admin_cannot_login_on_store_page(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Админ зала',
            'email' => 'floor@store.test',
            'password' => 'password',
            'role' => 'admin',
            'club_id' => $this->club->id,
        ]);

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/store/login')
            ->post('/store/login', [
                'email' => $admin->email,
                'password' => 'password',
            ])
            ->assertRedirect('/store/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest('admin');
    }

    public function test_pending_store_staff_cannot_open_warehouse(): void
    {
        $assembler = $this->makeAssembler(pending: true);

        $this->actingAs($assembler, 'admin')
            ->get('/admin/store/warehouse')
            ->assertRedirect('/store/hire');
    }

    public function test_pending_store_staff_is_redirected_from_salary_to_hire(): void
    {
        $assembler = $this->makeAssembler(pending: true);

        $this->actingAs($assembler, 'admin')
            ->get('/admin/salary')
            ->assertRedirect('/store/hire');
    }

    public function test_store_hire_submit_keeps_assembler_role(): void
    {
        Storage::fake('local');
        $assembler = $this->makeAssembler(pending: true);
        $this->actingAs($assembler, 'admin')->withoutMiddleware(ValidateCsrfToken::class);

        foreach (StaffEmploymentRules::ids() as $id) {
            $this->from('/store/hire')
                ->post('/store/hire/rules', ['rule_id' => $id])
                ->assertRedirect('/store/hire');
        }

        $this->from('/store/hire')
            ->post('/store/hire', [
                'full_name' => 'Иванов Иван Иванович',
                'passport_series' => '1234',
                'passport_number' => '567890',
                'issued_by' => 'ГУ МВД России по г. Москве',
                'issued_at' => '2020-01-15',
                'department_code' => '770-001',
                'birth_date' => '1998-05-20',
                'passport_scan' => UploadedFile::fake()->image('passport.jpg'),
            ])
            ->assertRedirect('/store/hire');

        $assembler->refresh();
        $this->assertSame('assembler', $assembler->role);
        $this->assertTrue((bool) $assembler->employment_pending);
        $this->assertDatabaseHas('staff_employment_profiles', [
            'admin_id' => $assembler->id,
            'status' => StaffEmploymentProfile::STATUS_REVIEW,
        ]);
    }

    public function test_hired_store_staff_logout_returns_to_store_login(): void
    {
        $assembler = $this->makeAssembler();

        $this->actingAs($assembler, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/logout')
            ->assertRedirect('/store/login');
    }

    private function makeAssembler(bool $pending = false): Admin
    {
        return Admin::query()->create([
            'name' => 'Сборщик',
            'email' => 'asm.'.uniqid().'@store.test',
            'password' => 'password',
            'role' => 'assembler',
            'club_id' => $this->club->id,
            'base_rate' => 2200,
            'pay_type' => 'shift',
            'employment_pending' => $pending,
        ]);
    }
}
