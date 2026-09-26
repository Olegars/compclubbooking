<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\CadreReport;
use App\Models\StaffCadreEvent;
use App\Models\StaffEmploymentProfile;
use App\Models\StaffLedger;
use Carbon\Carbon;
use DOMDocument;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StaffCadreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-24 12:00:00');
        config([
            'staff_cadre.employer.name' => 'ИП Тестов Тест Тестович',
            'staff_cadre.employer.inn' => '770000000082',
            'staff_cadre.employer.kpp' => '',
            'staff_cadre.employer.sfr_reg_number' => '000-000-000000',
            'staff_cadre.employer.tax_office' => '7700',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_invalid_snils_blocks_the_hire_form(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Кандидат',
            'email' => 'bad-snils@cadre.test',
            'password' => 'password',
            'role' => 'intern',
            'employment_pending' => true,
            'pay_type' => 'shift',
            'base_rate' => 1500,
        ]);

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/salary')
            ->post('/admin/salary/employment/hire', [
                'full_name' => 'Иванов Иван Иванович',
                'passport_series' => '1234',
                'passport_number' => '567890',
                'issued_by' => 'ГУ МВД России по г. Москве',
                'issued_at' => '2020-01-15',
                'department_code' => '770-001',
                'birth_date' => '1998-05-20',
                'snils' => '112-233-445 00',
                'inn' => '500100732259',
                'gender' => 'male',
            ])
            ->assertRedirect('/admin/salary')
            ->assertSessionHasErrors('snils');

        $this->assertDatabaseMissing('staff_cadre_events', ['admin_id' => $admin->id]);
    }

    public function test_fire_builds_efs1_and_blocks_a_broken_file(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $employee = $this->official('Иванов Иван Иванович');

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/staff/'.$employee->id.'/fire', ['fire_reason_code' => 'article_81_6a'])
            ->assertRedirect();

        $event = StaffCadreEvent::query()->where('admin_id', $employee->id)->where('event_type', 'fire')->first();
        $this->assertNotNull($event);
        $this->assertSame('article_81_6a', $event->fire_reason_code);
        $this->assertSame('pending', $event->status);

        $employee->employmentProfile->update(['snils' => null]);

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/taxes/cadre')
            ->post('/admin/taxes/cadre/efs1/generate', ['event_ids' => [$event->id]])
            ->assertRedirect('/admin/taxes/cadre')
            ->assertSessionHasErrors('cadre');

        $this->assertSame(0, CadreReport::query()->count());

        $employee->employmentProfile->update(['snils' => '112-233-445 95']);

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/taxes/cadre/efs1/generate', ['event_ids' => [$event->id]])
            ->assertRedirect()
            ->assertSessionHas('success');

        $report = CadreReport::query()->first();
        $this->assertNotNull($report);
        $this->assertSame('efs1_sub1_1', $report->report_type);
        $xml = Storage::disk('local')->get($report->file_path);
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
        $this->assertTrue($dom->schemaValidate(resource_path('xsd/efs1-subsection-1.1.xsd')));
        $this->assertStringContainsString('ппап6ч1с81', $xml);
        $this->assertStringContainsString('112-233-445 95', $xml);

        $this->actingAs($owner, 'admin')
            ->get('/admin/taxes/cadre/download/'.$report->id)
            ->assertOk();

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/taxes/cadre/mark-submitted/'.$report->id)
            ->assertRedirect();

        $this->assertSame('submitted', $report->fresh()->status);
        $this->assertSame('submitted', $event->fresh()->status);
    }

    public function test_pers_records_sum_accruals_without_fines(): void
    {
        Storage::fake('local');
        Carbon::setTestNow('2026-09-10 12:00:00');
        $owner = $this->owner();
        $employee = $this->official('Петров Пётр Петрович');
        $employee->forceFill([
            'hired_at' => '2026-08-01 10:00:00',
            'is_official_employee' => true,
        ])->save();

        $this->ledger($employee->id, 'accrual', 15000, '2026-08-20 12:00:00');
        $this->ledger($employee->id, 'accrual', 3000, '2026-08-28 12:00:00');
        $this->ledger($employee->id, 'fine', 500, '2026-08-28 12:00:00');
        $this->ledger($employee->id, 'accrual', 9000, '2026-09-02 12:00:00');

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/taxes/cadre/pers-records/generate', ['year' => 2026, 'month' => 8])
            ->assertRedirect()
            ->assertSessionHas('success');

        $report = CadreReport::query()->first();
        $raw = Storage::disk('local')->get($report->file_path);
        $xml = str_replace(
            'encoding="windows-1251"',
            'encoding="UTF-8"',
            mb_convert_encoding($raw, 'UTF-8', 'Windows-1251')
        );
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
        $this->assertTrue($dom->schemaValidate(resource_path('xsd/pers-records-1151162.xsd')));
        $this->assertStringContainsString('СумВыпл="18000.00"', $xml);
        $this->assertStringContainsString('КНД="1151162"', $xml);
        $this->assertStringNotContainsString('9000.00', $xml);
    }

    public function test_floor_admin_cannot_open_cadre_reports(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Админ зала',
            'email' => 'floor@cadre.test',
            'password' => 'password',
            'role' => 'admin',
            'employment_pending' => false,
            'pay_type' => 'shift',
            'base_rate' => 1500,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/taxes/cadre')
            ->assertRedirect('/admin/salary');
    }

    public function test_supervisor_opens_cadre_screen(): void
    {
        $supervisor = Admin::query()->create([
            'name' => 'Управляющий',
            'email' => 'sup@cadre.test',
            'password' => 'password',
            'role' => 'supervisor',
            'employment_pending' => false,
            'pay_type' => 'monthly',
            'base_rate' => 3000,
        ]);

        $this->actingAs($supervisor, 'admin')
            ->get('/admin/taxes/cadre')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/TaxesCadre')->has('events')->has('alerts'));
    }

    private function owner(): Admin
    {
        return Admin::query()->create([
            'name' => 'Владелец',
            'email' => 'owner-'.uniqid().'@cadre.test',
            'password' => 'password',
            'role' => 'owner',
            'employment_pending' => false,
            'pay_type' => 'monthly',
            'base_rate' => 0,
        ]);
    }

    private function official(string $name): Admin
    {
        $admin = Admin::query()->create([
            'name' => $name,
            'email' => 'emp-'.uniqid().'@cadre.test',
            'password' => 'password',
            'role' => 'admin',
            'employment_pending' => false,
            'is_official_employee' => true,
            'pay_type' => 'shift',
            'base_rate' => 2000,
            'hired_at' => now()->subMonth(),
        ]);

        StaffEmploymentProfile::query()->create([
            'admin_id' => $admin->id,
            'full_name' => $name,
            'passport_series' => '1234',
            'passport_number' => '567890',
            'issued_by' => 'ГУ МВД России по г. Москве',
            'issued_at' => '2020-01-15',
            'department_code' => '770-001',
            'birth_date' => '1998-05-20',
            'snils' => '112-233-445 95',
            'inn' => '500100732259',
            'gender' => 'male',
            'status' => StaffEmploymentProfile::STATUS_APPROVED,
            'accepted_rule_ids' => [],
            'accepted_fire_rule_ids' => [],
        ]);

        return $admin;
    }

    private function ledger(int $adminId, string $type, float $amount, string $when): void
    {
        $row = StaffLedger::query()->create([
            'admin_id' => $adminId,
            'type' => $type,
            'amount' => $amount,
            'reason' => $type,
        ]);
        $row->timestamps = false;
        $row->created_at = Carbon::parse($when);
        $row->save();
    }
}
