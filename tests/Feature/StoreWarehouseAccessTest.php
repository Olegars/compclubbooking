<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Models\StoreBuiltPc;
use App\Models\StoreBuiltPcComponent;
use App\Models\StoreComponent;
use App\Models\StoreWarranty;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreWarehouseAccessTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->club = Club::query()->create([
            'name' => 'PC Store',
            'slug' => 'pc-store-'.uniqid(),
            'type' => 'store',
        ]);
    }

    public function test_assembler_reads_warehouse_but_cannot_receive_or_edit_price(): void
    {
        $assembler = $this->makeStaff('assembler');
        $component = $this->makeComponent(15000);

        $this->actingAs($assembler, 'admin')
            ->get('/admin/store/warehouse')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Store/Warehouse')
                ->where('canReceive', false)
                ->where('canManage', false)
            );

        $this->actingAs($assembler, 'admin')
            ->post('/admin/store/warehouse', [
                'name' => 'SSD',
                'type' => 'storage_ssd',
                'purchase_price' => 5000,
                'status' => 'in_stock',
            ])
            ->assertForbidden();

        $this->actingAs($assembler, 'admin')
            ->put('/admin/store/warehouse/'.$component->id, [
                'name' => $component->name,
                'type' => 'cpu',
                'purchase_price' => 1,
                'status' => 'used',
            ])
            ->assertForbidden();

        $this->assertSame('15000.00', $component->fresh()->purchase_price);
        $this->assertSame('in_stock', $component->fresh()->status);
    }

    public function test_manager_can_receive_onto_warehouse(): void
    {
        $manager = $this->makeStaff('store_manager');

        $this->actingAs($manager, 'admin')
            ->get('/admin/store/warehouse')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canReceive', true)
                ->where('canManage', true)
            );

        $this->actingAs($manager, 'admin')
            ->post('/admin/store/warehouse', [
                'name' => 'SSD',
                'type' => 'storage_ssd',
                'purchase_price' => 5000,
                'status' => 'in_stock',
            ])
            ->assertRedirect();

        $this->assertNotNull(StoreComponent::query()->where('club_id', $this->club->id)->where('name', 'SSD')->first());
    }

    public function test_assembler_can_send_own_part_to_repair_without_changing_price(): void
    {
        $assembler = $this->makeStaff('assembler');
        $other = $this->makeStaff('assembler');
        $component = $this->makeComponent(15000, 'used');
        $foreignComponent = $this->makeComponent(9000, 'used');
        $warranty = $this->makeWarranty($assembler, $component);
        $foreign = $this->makeWarranty($other, $foreignComponent);

        $this->actingAs($assembler, 'admin')
            ->post('/admin/store/warranty/'.$warranty->id.'/send-to-repair', [
                'store_component_id' => $component->id,
            ])
            ->assertRedirect();

        $fresh = $component->fresh();
        $this->assertSame('repair', $fresh->status);
        $this->assertSame('15000.00', $fresh->purchase_price);

        $this->actingAs($assembler, 'admin')
            ->post('/admin/store/warranty/'.$foreign->id.'/send-to-repair', [
                'store_component_id' => $foreignComponent->id,
            ])
            ->assertForbidden();

        $this->actingAs($assembler, 'admin')
            ->post('/admin/store/warranty/'.$warranty->id.'/return-from-repair', [
                'store_component_id' => $component->id,
            ])
            ->assertForbidden();

        $this->actingAs($assembler, 'admin')
            ->post('/admin/store/warranty/'.$warranty->id.'/replace-component', [
                'store_component_id' => $component->id,
                'purchase_price' => 1,
            ])
            ->assertForbidden();

        $this->assertSame('repair', $component->fresh()->status);
        $this->assertSame('15000.00', $component->fresh()->purchase_price);
    }

    private function makeStaff(string $role): Admin
    {
        return Admin::query()->create([
            'name' => $role,
            'email' => $role.'.'.uniqid().'@store.test',
            'password' => 'password',
            'role' => $role,
            'club_id' => $this->club->id,
            'base_rate' => 2200,
            'pay_type' => 'shift',
            'employment_pending' => false,
        ]);
    }

    private function makeComponent(float $price, string $status = 'in_stock'): StoreComponent
    {
        return StoreComponent::query()->create([
            'club_id' => $this->club->id,
            'name' => 'CPU',
            'type' => 'cpu',
            'purchase_price' => $price,
            'qty' => 1,
            'status' => $status,
        ]);
    }

    private function makeWarranty(Admin $assembler, StoreComponent $component): StoreWarranty
    {
        $pc = StoreBuiltPc::query()->create([
            'club_id' => $this->club->id,
            'assembled_by' => $assembler->id,
            'status' => 'assembling',
            'serial_number' => (string) random_int(1000000000, 9999999999),
        ]);
        StoreBuiltPcComponent::query()->create([
            'store_built_pc_id' => $pc->id,
            'store_component_id' => $component->id,
            'type' => $component->type,
            'name' => $component->name,
        ]);

        return StoreWarranty::query()->create([
            'club_id' => $this->club->id,
            'store_built_pc_id' => $pc->id,
            'product_name' => 'ПК',
            'status' => 'active',
        ]);
    }
}
