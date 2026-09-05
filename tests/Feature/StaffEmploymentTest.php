<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\StaffEmploymentProfile;
use App\Support\StaffEmploymentRules;
use App\Support\StaffFireSafetyRules;
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
                ->where('employment.status', 'draft')
                ->where('employment.rules_title', 'Условия работы администратора')
                ->has('employment.rules', 10)
            );
    }

    public function test_cannot_submit_until_all_rules_and_passport_are_complete(): void
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

    public function test_submit_sends_application_to_review_without_hiring(): void
    {
        Storage::fake('local');
        $admin = $this->pendingIntern();
        $this->submitComplete($admin);

        $admin->refresh();
        $this->assertTrue((bool) $admin->employment_pending);
        $this->assertNull($admin->hired_at);
        $this->assertSame('Иванов Иван Иванович', $admin->name);
        $this->assertDatabaseHas('staff_employment_profiles', [
            'admin_id' => $admin->id,
            'status' => StaffEmploymentProfile::STATUS_REVIEW,
            'passport_series' => '1234',
            'passport_number' => '567890',
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('employment.required', true)
                ->where('employment.status', 'review')
            );
    }

    public function test_intern_cannot_accept_fire_rules_before_biometrics(): void
    {
        Storage::fake('local');
        $admin = $this->pendingIntern();
        $this->submitComplete($admin);

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/salary')
            ->post('/admin/salary/employment/fire-rules', ['rule_id' => 1])
            ->assertRedirect('/admin/salary')
            ->assertSessionHasErrors();
    }

    public function test_intern_cannot_edit_application_while_on_review(): void
    {
        Storage::fake('local');
        $admin = $this->pendingIntern();
        $this->submitComplete($admin);

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/salary')
            ->post('/admin/salary/employment/rules', ['rule_id' => 1])
            ->assertRedirect('/admin/salary')
            ->assertSessionHasErrors();
    }

    public function test_owner_pipeline_hires_after_appointment_biometrics_and_fire_rules(): void
    {
        Storage::fake('local');
        $intern = $this->pendingIntern();
        $this->submitComplete($intern);
        $owner = $this->makeReviewer('owner');
        $visitAt = now()->addDay()->format('Y-m-d H:i:s');

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/staff')
            ->post("/admin/staff/{$intern->id}/employment/appointment", [
                'appointment_at' => $visitAt,
            ])
            ->assertRedirect('/admin/staff');

        $this->assertDatabaseHas('staff_employment_profiles', [
            'admin_id' => $intern->id,
            'status' => StaffEmploymentProfile::STATUS_INVITED,
            'reviewed_by' => $owner->id,
        ]);
        $this->assertTrue((bool) $intern->fresh()->employment_pending);

        $this->actingAs($intern, 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('employment.required', true)
                ->where('employment.status', 'invited')
                ->has('employment.appointment_at')
            );

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/staff')
            ->post("/admin/staff/{$intern->id}/employment/biometrics")
            ->assertRedirect('/admin/staff');

        $this->assertDatabaseHas('staff_employment_profiles', [
            'admin_id' => $intern->id,
            'status' => StaffEmploymentProfile::STATUS_FIRE_SAFETY,
        ]);
        $this->assertTrue((bool) $intern->fresh()->employment_pending);

        $this->actingAs($intern, 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('employment.required', true)
                ->where('employment.status', 'fire_safety')
                ->has('employment.fire_rules', 8)
            );

        $this->actingAs($intern, 'admin')->withoutMiddleware(ValidateCsrfToken::class);
        foreach (StaffFireSafetyRules::ids() as $id) {
            $this->from('/admin/salary')
                ->post('/admin/salary/employment/fire-rules', ['rule_id' => $id])
                ->assertRedirect('/admin/salary');
        }

        $intern->refresh();
        $this->assertFalse((bool) $intern->employment_pending);
        $this->assertNotNull($intern->hired_at);
        $this->assertDatabaseHas('staff_employment_profiles', [
            'admin_id' => $intern->id,
            'status' => StaffEmploymentProfile::STATUS_APPROVED,
        ]);

        $this->actingAs($intern, 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('employment.required', false));

        $this->actingAs($owner, 'admin')
            ->get('/admin/staff')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('staff', function ($staff) use ($intern) {
                $row = collect($staff)->firstWhere('id', $intern->id);

                return $row
                    && data_get($row, 'employment.status') === StaffEmploymentProfile::STATUS_APPROVED
                    && data_get($row, 'employment.full_name') === 'Иванов Иван Иванович'
                    && data_get($row, 'employment.passport_number') === '567890'
                    && data_get($row, 'employment.has_scan') === true;
            }));
    }

    public function test_cannot_reject_after_biometrics(): void
    {
        Storage::fake('local');
        $intern = $this->pendingIntern();
        $this->submitComplete($intern);
        $owner = $this->makeReviewer('owner');

        $this->actingAs($owner, 'admin')->withoutMiddleware(ValidateCsrfToken::class)
            ->post("/admin/staff/{$intern->id}/employment/appointment", [
                'appointment_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect();

        $this->post("/admin/staff/{$intern->id}/employment/biometrics")->assertRedirect();

        $this->from('/admin/staff')
            ->post("/admin/staff/{$intern->id}/employment/reject", [
                'reason' => 'Передумал после биометрии',
            ])
            ->assertRedirect('/admin/staff')
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('staff_employment_profiles', [
            'admin_id' => $intern->id,
            'status' => StaffEmploymentProfile::STATUS_FIRE_SAFETY,
        ]);
    }

    public function test_supervisor_can_reject_and_intern_sees_reason(): void
    {
        Storage::fake('local');
        $intern = $this->pendingIntern();
        $this->submitComplete($intern);
        $supervisor = $this->makeReviewer('supervisor');

        $this->actingAs($supervisor, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/staff')
            ->post("/admin/staff/{$intern->id}/employment/reject", [
                'reason' => 'Нечитаемый скан паспорта',
            ])
            ->assertRedirect('/admin/staff');

        $intern->refresh();
        $this->assertTrue((bool) $intern->employment_pending);
        $this->assertNull($intern->hired_at);

        $this->actingAs($intern, 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('employment.required', true)
                ->where('employment.status', 'rejected')
                ->where('employment.rejection_reason', 'Нечитаемый скан паспорта')
            );
    }

    public function test_rejected_intern_can_resubmit(): void
    {
        Storage::fake('local');
        $intern = $this->pendingIntern();
        $this->submitComplete($intern);
        $owner = $this->makeReviewer('owner');

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post("/admin/staff/{$intern->id}/employment/reject", [
                'reason' => 'Нечитаемый скан паспорта',
            ])
            ->assertRedirect();

        $this->actingAs($intern, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/salary')
            ->post('/admin/salary/employment/hire', $this->hirePayload([
                'passport_scan' => UploadedFile::fake()->image('passport2.jpg'),
            ]))
            ->assertRedirect('/admin/salary');

        $this->assertDatabaseHas('staff_employment_profiles', [
            'admin_id' => $intern->id,
            'status' => StaffEmploymentProfile::STATUS_REVIEW,
            'rejection_reason' => null,
        ]);
    }

    public function test_floor_admin_cannot_review_employment(): void
    {
        Storage::fake('local');
        $intern = $this->pendingIntern();
        $this->submitComplete($intern);
        $admin = $this->makeReviewer('admin');
        \App\Models\Shift::create([
            'admin_id' => $admin->id,
            'status' => 'open',
            'started_at' => now()->subHour(),
            'cash_start' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post("/admin/staff/{$intern->id}/employment/appointment", [
                'appointment_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->assertForbidden();
    }

    public function test_supervisor_can_open_staff_page_with_review_queue(): void
    {
        Storage::fake('local');
        $intern = $this->pendingIntern();
        $this->submitComplete($intern);
        $supervisor = $this->makeReviewer('supervisor');

        $this->actingAs($supervisor, 'admin')
            ->get('/admin/staff')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Staff')
                ->has('staff')
            );
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

    private function makeReviewer(string $role): Admin
    {
        return Admin::create([
            'name' => ucfirst($role),
            'email' => $role.'.'.uniqid().'@slots.test',
            'password' => 'password',
            'role' => $role,
            'base_rate' => 3000,
            'pay_type' => 'shift',
            'employment_pending' => false,
        ]);
    }

    private function submitComplete(Admin $admin): void
    {
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
