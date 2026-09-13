<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Models\StoreClient;
use App\Models\StoreEstimate;
use App\Models\StoreOrder;
use App\Models\StoreWarranty;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreStaffCabinetTest extends TestCase
{
    use RefreshDatabase;

    private Club $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = Club::query()->create([
            'name' => 'Store Hub',
            'slug' => 'store-hub-'.uniqid(),
            'type' => 'both',
        ]);
    }

    public function test_assembler_cabinet_shows_own_queue_and_can_take_new_order(): void
    {
        $assembler = $this->makeStoreStaff('assembler', 2200);
        $other = $this->makeStoreStaff('assembler', 2200);
        $client = StoreClient::query()->create([
            'club_id' => $this->club->id,
            'name' => 'Иван Клиент',
            'phone' => '79001112233',
        ]);
        $open = StoreOrder::query()->create([
            'club_id' => $this->club->id,
            'store_client_id' => $client->id,
            'status' => 'new',
            'total' => 150000,
        ]);
        StoreOrder::query()->create([
            'club_id' => $this->club->id,
            'store_client_id' => $client->id,
            'assignee_id' => $other->id,
            'status' => 'assembling',
            'total' => 80000,
        ]);

        $this->actingAs($assembler, 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Salary')
                ->where('store_desk.role', 'assembler')
                ->where('store_desk.permissions.can_take_orders', true)
                ->where('store_desk.permissions.can_manage', false)
                ->has('store_desk.orders', 1)
                ->where('store_desk.orders.0.id', $open->id)
            );

        $this->actingAs($assembler, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->from('/admin/salary')
            ->post("/admin/store/orders/{$open->id}/status", ['status' => 'assembling'])
            ->assertRedirect('/admin/salary');

        $this->assertDatabaseHas('store_orders', [
            'id' => $open->id,
            'status' => 'assembling',
            'assignee_id' => $assembler->id,
        ]);
    }

    public function test_manager_cabinet_sees_estimates_and_cannot_cancel(): void
    {
        $manager = $this->makeStoreStaff('store_manager', 2500);
        StoreEstimate::query()->create([
            'club_id' => $this->club->id,
            'created_by' => $manager->id,
            'title' => 'Игровой ПК',
            'status' => 'agreed',
            'sale_total' => 120000,
            'purchase_total' => 90000,
        ]);
        StoreOrder::query()->create([
            'club_id' => $this->club->id,
            'status' => 'ready',
            'total' => 120000,
        ]);

        $this->actingAs($manager, 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Salary')
                ->where('store_desk.role', 'store_manager')
                ->where('store_desk.permissions.can_manage', true)
                ->where('store_desk.permissions.can_cancel', false)
                ->where('store_desk.counts.orders_ready', 1)
                ->has('store_desk.estimates', 1)
            );
    }

    public function test_senior_manager_sees_team_and_warranty_claims(): void
    {
        $senior = $this->makeStoreStaff('senior_manager', 3500, 'monthly');
        $this->makeStoreStaff('assembler', 2200);
        $this->makeStoreStaff('store_manager', 2500);
        StoreWarranty::query()->create([
            'club_id' => $this->club->id,
            'product_name' => 'Системный блок',
            'status' => 'claimed',
            'claim_notes' => 'Не включается',
        ]);

        $this->actingAs($senior, 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Salary')
                ->where('store_desk.role', 'senior_manager')
                ->where('store_desk.permissions.can_cancel', true)
                ->where('store_desk.permissions.can_close_warranty', true)
                ->where('store_desk.counts.warranties_claimed', 1)
                ->has('store_desk.team', 3)
            );
    }

    public function test_club_admin_cabinet_has_no_store_desk(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Админ зала',
            'email' => 'floor.'.uniqid().'@cabinet.test',
            'password' => 'password',
            'role' => 'admin',
            'club_id' => $this->club->id,
            'base_rate' => 2000,
            'pay_type' => 'shift',
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Salary')
                ->where('store_desk', null)
            );
    }

    public function test_store_login_lands_on_personal_cabinet(): void
    {
        $assembler = $this->makeStoreStaff('assembler', 2200);

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/store/login', [
                'email' => $assembler->email,
                'password' => 'password',
            ])->assertRedirect(route('admin.salary'));
    }

    private function makeStoreStaff(string $role, float $rate, string $payType = 'shift'): Admin
    {
        return Admin::query()->create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.'.'.uniqid().'@cabinet.test',
            'password' => 'password',
            'role' => $role,
            'club_id' => $this->club->id,
            'base_rate' => $rate,
            'pay_type' => $payType,
        ]);
    }
}
