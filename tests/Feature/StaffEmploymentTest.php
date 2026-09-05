<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Support\StaffEmploymentRules;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StaffEmploymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_opens_personal_cabinet_with_employment_form(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/register', [
                'name' => 'Иван Тестов',
                'email' => 'newhire@slots.test',
                'password' => 'secret12',
                'password_confirmation' => 'secret12',
            ])
            ->assertRedirect('/admin/salary');

        $this->assertDatabaseHas('admins', [
            'email' => 'newhire@slots.test',
            'role' => 'intern',
            'employment_pending' => true,
        ]);

        $this->actingAs(Admin::query()->where('email', 'newhire@slots.test')->first(), 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Salary')
                ->where('employment.required', true)
                ->has('employment.rules', 10)
            );
    }

    public function test_cannot_hire_until_all_rules_and_passport_are_complete(): void
    {
        $admin = $this->pendingIntern();

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/salary')
            ->post('/admin/salary/employment/hire', $this->hirePayload())
            ->assertRedirect('/admin/salary')
            ->assertSessionHasErrors();

        $this->assertTrue($admin->fresh()->employment_pending);
    }

    public function test_accepting_rules_then_hire_completes_employment(): void
    {
        Storage::fake('local');
        $admin = $this->pendingIntern();

        $this->actingAs($admin, 'admin')->withoutMiddleware(ValidateCsrfToken::class);

        foreach (StaffEmploymentRules::ids() as $id) {
            $this->from('/admin/salary')
                ->post('/admin/salary/employment/rules', ['rule_id' => $id])
                ->assertRedirect('/admin/salary');
        }

        $this->from('/admin/salary')
            ->post('/admin/salary/employment/hire', $this->hirePayload([
                'passport_scan' => UploadedFile::fake()->image('passport.jpg'),
            ]))
            ->assertRedirect('/admin/salary');

        $admin->refresh();
        $this->assertFalse((bool) $admin->employment_pending);
        $this->assertNotNull($admin->hired_at);
        $this->assertSame('Иванов Иван Иванович', $admin->name);
        $this->assertDatabaseHas('staff_employment_profiles', [
            'admin_id' => $admin->id,
            'passport_series' => '1234',
            'passport_number' => '567890',
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('employment.required', false));
    }

    public function test_existing_staff_skips_employment(): void
    {
        $admin = Admin::create([
            'name' => 'Админ',
            'email' => 'old@slots.test',
            'password' => 'password',
            'role' => 'admin',
            'base_rate' => 2000,
            'pay_type' => 'shift',
            'employment_pending' => false,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('employment.required', false));
    }

    private function pendingIntern(): Admin
    {
        return Admin::create([
            'name' => 'Стажёр',
            'email' => 'pending.'.uniqid().'@slots.test',
            'password' => 'password',
            'role' => 'intern',
            'base_rate' => 1500,
            'pay_type' => 'shift',
            'employment_pending' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function hirePayload(array $extra = []): array
    {
        return array_merge([
            'full_name' => 'Иванов Иван Иванович',
            'passport_series' => '1234',
            'passport_number' => '567890',
            'issued_by' => 'ГУ МВД России по г. Москве',
            'issued_at' => '2020-01-15',
            'department_code' => '770-001',
            'birth_date' => '1998-05-20',
        ], $extra);
    }
}
