<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Models\Shift;
use App\Models\ShiftSlot;
use App\Models\ShiftSlotBooking;
use App\Models\ShiftSlotTemplate;
use App\Models\StaffDisciplinaryIncident;
use App\Models\StaffEdoAgreement;
use App\Models\StaffPresencePing;
use App\Models\StaffQuarterReserve;
use App\Services\StaffEdoService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffEdoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'staff_edo.test_otp' => '123456',
            'staff_edo.reveal_otp' => false,
        ]);
    }

    public function test_hired_admin_signs_edo_agreement_with_otp(): void
    {
        $admin = $this->makeAdmin('admin');
        $admin->update(['hired_at' => now()]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('edo.needs_agreement', true));

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/salary/edo/otp', [
                'purpose' => 'agreement',
                'phone' => '+7 999 000-11-22',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/salary/edo/sign', [
                'phone' => '79990001122',
                'code' => '123456',
                'scrolled' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('staff_edo_agreements', [
            'admin_id' => $admin->id,
            'phone_number' => '79990001122',
            'agreement_version' => '2026-09-1',
        ]);
    }

    public function test_missed_slot_keeps_cabinet_open_until_excuse(): void
    {
        $admin = $this->makeAdmin('supervisor');
        $admin->update(['hired_at' => now()]);
        StaffEdoAgreement::query()->create([
            'admin_id' => $admin->id,
            'agreement_version' => '2026-09-1',
            'phone_number' => '79990001122',
            'signed_at' => now(),
            'ip_address' => '127.0.0.1',
            'otp_code_hash' => hash('sha256', 'x'),
            'document_hash' => hash('sha256', 'y'),
        ]);
        $this->bookPastSlot($admin, ShiftSlotBooking::KIND_LEAD);

        $this->assertSame(1, app(StaffEdoService::class)->scanAbsences());

        $this->actingAs($admin, 'admin')
            ->get('/admin/dashboard')
            ->assertOk();

        $this->actingAs($admin, 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('edo.blocking', false)
                ->where('edo.incident.type', 'shift_absence')
            );

        $incident = StaffDisciplinaryIncident::query()->first();
        $this->assertDatabaseHas('staff_edo_documents', [
            'incident_id' => $incident->id,
            'doc_type' => 'report_memo_draft',
        ]);
        $this->assertNotNull($incident->evidence_meta['manager_notified_at'] ?? null);

        $incident = StaffDisciplinaryIncident::query()->first();
        $this->assertNotNull($incident->demand_delivered_at);
        $this->assertNotNull($incident->deadline_at);

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/salary/edo/otp', ['purpose' => 'explanation'])
            ->assertRedirect();

        $this->actingAs($admin, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/salary/edo/explanation', [
                'text' => 'Был в травмпункте, справку приложу отдельно.',
                'code' => '123456',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $owner = $this->makeAdmin('owner');
        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/staff/incidents/'.$incident->id.'/resolve', ['decision' => 'excuse'])
            ->assertRedirect();

        $this->assertSame(
            StaffDisciplinaryIncident::STATUS_EXCUSED,
            $incident->fresh()->status
        );

        $this->actingAs($admin, 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('edo.blocking', false));
    }

    public function test_silence_after_deadline_builds_dismissal_archive(): void
    {
        $admin = $this->makeAdmin('admin');
        $this->bookPastSlot($admin, ShiftSlotBooking::KIND_LEAD);
        app(StaffEdoService::class)->scanAbsences();
        $incident = StaffDisciplinaryIncident::query()->firstOrFail();
        $incident->forceFill([
            'demand_delivered_at' => now()->subDays(10),
            'deadline_at' => now()->subDay(),
        ])->save();

        StaffQuarterReserve::query()->create([
            'admin_id' => $admin->id,
            'year' => now()->year,
            'quarter' => (int) ceil(now()->month / 3),
            'points' => 40,
        ]);

        $this->assertSame(1, app(StaffEdoService::class)->expireDeadlines());
        $this->assertSame(StaffDisciplinaryIncident::STATUS_EXPIRED, $incident->fresh()->status);

        $owner = $this->makeAdmin('owner');
        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/staff/incidents/'.$incident->id.'/resolve', ['decision' => 'confirm_dismissal'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $incident->refresh();
        $this->assertSame(StaffDisciplinaryIncident::STATUS_MEMO, $incident->status);
        $this->assertEquals(0.0, (float) $incident->xp_forfeited);
        $this->assertDatabaseMissing('staff_sfr_events', [
            'incident_id' => $incident->id,
        ]);
        $this->assertSame(40.0, (float) StaffQuarterReserve::query()->where('admin_id', $admin->id)->value('points'));
        $this->assertDatabaseHas('staff_edo_documents', [
            'incident_id' => $incident->id,
            'doc_type' => 'report_memo',
            'doc_title' => 'Докладная записка',
        ]);

        $this->actingAs($owner, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/staff/incidents/'.$incident->id.'/sign')
            ->assertRedirect()
            ->assertSessionHas('success');

        $memo = \App\Models\StaffEdoDocument::query()
            ->where('incident_id', $incident->id)
            ->where('doc_type', 'report_memo')
            ->first();
        $this->assertSame($owner->id, $memo->signatures[0]['admin_id'] ?? null);
        $this->assertSame(40.0, (float) StaffQuarterReserve::query()->where('admin_id', $admin->id)->value('points'));
        $this->assertFalse(app(StaffEdoService::class)->isBlocked($admin));

        $path = app(StaffEdoService::class)->exportDossier($incident->fresh());
        $this->assertFileExists($path);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $this->assertFalse($zip->locateName('t8-order.html'));
        $this->assertFalse($zip->locateName('efs-1.json'));
        $zip->close();
        @unlink($path);
    }

    public function test_abandonment_needs_a_stale_reception_ping(): void
    {
        $admin = $this->makeAdmin('admin');
        Shift::query()->create([
            'admin_id' => $admin->id,
            'status' => 'open',
            'started_at' => now()->subHours(6),
            'cash_start' => 0,
        ]);

        $this->assertSame(0, app(StaffEdoService::class)->scanAbandonments());

        StaffPresencePing::query()->create([
            'admin_id' => $admin->id,
            'seen_at' => now()->subHours(5),
            'source' => 'face',
        ]);

        $this->assertSame(1, app(StaffEdoService::class)->scanAbandonments());
        $this->assertDatabaseHas('staff_disciplinary_incidents', [
            'admin_id' => $admin->id,
            'incident_type' => StaffDisciplinaryIncident::TYPE_ABANDONMENT,
        ]);
    }

    public function test_presence_relay_rejects_empty_token(): void
    {
        config(['video_surveillance.relay_token' => '']);
        $admin = $this->makeAdmin('admin');

        $this->postJson('/api/video/staff-presence', ['admin_id' => $admin->id])
            ->assertUnauthorized();
    }

    private function makeAdmin(string $role): Admin
    {
        $club = Club::query()->first() ?? Club::query()->create([
            'name' => 'Edo Club',
            'slug' => 'edo-club',
            'type' => 'club',
        ]);

        return Admin::query()->create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.'.'.uniqid().'@edo.test',
            'password' => 'password',
            'role' => $role,
            'club_id' => $club->id,
            'base_rate' => 2000,
            'pay_type' => 'shift',
        ]);
    }

    private function bookPastSlot(Admin $admin, string $kind): void
    {
        $template = ShiftSlotTemplate::query()->create([
            'club_id' => $admin->club_id,
            'name' => 'Слот '.uniqid(),
            'starts_time' => '10:00:00',
            'duration_hours' => 12,
            'intern_capacity' => 1,
            'is_active' => true,
        ]);
        $slot = ShiftSlot::query()->create([
            'club_id' => $admin->club_id,
            'template_id' => $template->id,
            'starts_at' => Carbon::now()->subMinutes(20),
            'ends_at' => Carbon::now()->addHours(11),
            'intern_capacity' => 1,
        ]);
        ShiftSlotBooking::query()->create([
            'shift_slot_id' => $slot->id,
            'admin_id' => $admin->id,
            'kind' => $kind,
            'status' => ShiftSlotBooking::STATUS_BOOKED,
        ]);
    }
}
