<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StaffLedger;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftHandoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_presence_is_required_to_begin_handover(): void
    {
        $outgoing = $this->makeAdmin('admin');
        $incoming = $this->makeAdmin('admin');
        $this->openShift($outgoing);

        $this->actingAs($incoming, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/shifts/begin', ['verified' => false])
            ->assertStatus(422);
    }

    public function test_incoming_begin_freezes_outgoing_ops(): void
    {
        $outgoing = $this->makeAdmin('admin');
        $incoming = $this->makeAdmin('admin');
        $shift = $this->openShift($outgoing);

        $this->actingAs($incoming, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/shifts/begin', [
                'verified' => true,
                'camera' => 'reception',
                'face_detected' => true,
            ])
            ->assertOk()
            ->assertJsonPath('phase', 'counting');

        $this->assertDatabaseHas('shifts', [
            'id' => $shift->id,
            'status' => 'transferring',
            'incoming_admin_id' => $incoming->id,
        ]);

        $this->actingAs($outgoing, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/shifts/complete', ['cash_counted' => 0])
            ->assertStatus(423);

        $this->actingAs($outgoing, 'admin')
            ->getJson('/admin/api/shifts/status')
            ->assertOk()
            ->assertJsonPath('admin_shift.overlay', 'transferring');
    }

    public function test_intern_cannot_begin_handover(): void
    {
        $lead = $this->makeAdmin('admin');
        $intern = $this->makeAdmin('intern', 1500);
        $this->openShift($lead);

        $this->actingAs($intern, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/shifts/begin', ['verified' => true])
            ->assertStatus(403);
    }

    public function test_scan_count_and_complete_charges_shortage_to_outgoing(): void
    {
        $outgoing = $this->makeAdmin('admin', 2000);
        $incoming = $this->makeAdmin('admin', 2000);
        $this->openShift($outgoing);

        $cola = Product::create([
            'name' => 'Cola',
            'category' => 'Напитки',
            'price' => 100,
            'cost_price' => 40,
            'stock' => 5,
            'barcode' => '4600000000017',
            'image' => '',
            'is_active' => true,
            'requires_marking' => false,
        ]);
        Product::create([
            'name' => 'Пустой',
            'category' => 'Напитки',
            'price' => 50,
            'cost_price' => 10,
            'stock' => 0,
            'barcode' => '4600000000024',
            'image' => '',
            'is_active' => true,
            'requires_marking' => false,
        ]);

        $this->actingAs($incoming, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/shifts/begin', ['verified' => true])
            ->assertOk();

        $this->actingAs($incoming, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/shifts/scan', ['code' => '4600000000017'])
            ->assertOk()
            ->assertJsonPath('product.id', $cola->id)
            ->assertJsonPath('product.counted', false);

        $this->actingAs($incoming, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/shifts/count', ['product_id' => $cola->id, 'qty' => 3])
            ->assertOk()
            ->assertJsonPath('counted_required', 1)
            ->assertJsonPath('can_complete', true);

        $counted = $this->actingAs($incoming, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/shifts/count', ['product_id' => $cola->id, 'qty' => 3])
            ->assertOk();
        $colaRow = collect($counted->json('products'))->firstWhere('id', $cola->id);
        $this->assertSame(3, (int) $colaRow['actual']);

        $this->actingAs($incoming, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/api/shifts/complete', ['cash_counted' => 1200])
            ->assertRedirect('/admin/dashboard');

        $this->assertDatabaseHas('shifts', [
            'admin_id' => $incoming->id,
            'status' => 'open',
            'cash_start' => 1200,
        ]);
        $this->assertDatabaseHas('shifts', [
            'admin_id' => $outgoing->id,
            'status' => 'closed',
            'cash_end' => 1200,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $cola->id,
            'stock' => 3,
        ]);
        $this->assertDatabaseHas('staff_ledgers', [
            'admin_id' => $outgoing->id,
            'type' => StaffLedger::TYPE_FINE,
            'amount' => 80,
            'created_by' => $incoming->id,
        ]);

        $outgoing->refresh();
        $this->assertNotNull($outgoing->shift_handed_over_at);

        $this->actingAs($outgoing, 'admin')
            ->getJson('/admin/api/shifts/status')
            ->assertOk()
            ->assertJsonPath('admin_shift.overlay', 'handed_over');

        $this->actingAs($outgoing, 'admin')->get('/admin/salary')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Salary')
                ->where('fines_total', fn ($total) => (float) $total === 80.0)
                ->where('fines.0.reason', fn ($reason) => str_contains((string) $reason, 'Недостача при передаче смены'))
            );
    }

    public function test_matching_count_does_not_fine_outgoing(): void
    {
        $outgoing = $this->makeAdmin('admin');
        $incoming = $this->makeAdmin('admin');
        $this->openShift($outgoing);

        $product = Product::create([
            'name' => 'Sprite',
            'category' => 'Напитки',
            'price' => 90,
            'cost_price' => 30,
            'stock' => 4,
            'barcode' => '4600000000031',
            'image' => '',
            'is_active' => true,
            'requires_marking' => false,
        ]);

        $this->actingAs($incoming, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/shifts/begin', ['verified' => true])
            ->assertOk();

        $this->actingAs($incoming, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/shifts/count', ['product_id' => $product->id, 'qty' => 4])
            ->assertOk()
            ->assertJsonPath('can_complete', true);

        $this->actingAs($incoming, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/api/shifts/complete', ['cash_counted' => 0])
            ->assertRedirect('/admin/dashboard');

        $this->assertDatabaseMissing('staff_ledgers', [
            'admin_id' => $outgoing->id,
            'type' => StaffLedger::TYPE_FINE,
        ]);
    }

    public function test_cannot_complete_until_required_stock_is_counted(): void
    {
        $outgoing = $this->makeAdmin('admin');
        $incoming = $this->makeAdmin('admin');
        $this->openShift($outgoing);

        Product::create([
            'name' => 'Fanta',
            'category' => 'Напитки',
            'price' => 90,
            'cost_price' => 30,
            'stock' => 2,
            'barcode' => '4600000000048',
            'image' => '',
            'is_active' => true,
            'requires_marking' => false,
        ]);

        $this->actingAs($incoming, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/admin/api/shifts/begin', ['verified' => true])
            ->assertOk();

        $this->actingAs($incoming, 'admin')
            ->from('/admin/shifts/transfer')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/api/shifts/complete', ['cash_counted' => 0])
            ->assertRedirect('/admin/shifts/transfer')
            ->assertSessionHasErrors('message');
    }

    private function makeAdmin(string $role, ?float $rate = 2000): Admin
    {
        return Admin::create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.'.'.uniqid().'@handover.test',
            'password' => 'password',
            'role' => $role,
            'base_rate' => $rate,
            'pay_type' => 'shift',
        ]);
    }

    private function openShift(Admin $admin): Shift
    {
        return Shift::create([
            'admin_id' => $admin->id,
            'status' => 'open',
            'started_at' => now()->subHour(),
            'cash_start' => 0,
        ]);
    }
}
