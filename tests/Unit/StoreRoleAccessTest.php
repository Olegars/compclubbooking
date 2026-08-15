<?php

namespace Tests\Unit;

use App\Models\Admin;
use App\Models\Club;
use PHPUnit\Framework\TestCase;

class StoreRoleAccessTest extends TestCase
{
    public function test_club_roles_can_access_store_at_club_shop(): void
    {
        $admin = new Admin(['role' => 'admin']);
        $supervisor = new Admin(['role' => 'supervisor']);
        $owner = new Admin(['role' => 'owner']);

        $this->assertTrue($admin->canAccessClub());
        $this->assertTrue($admin->canAccessStore());

        $this->assertTrue($supervisor->canAccessClub());
        $this->assertTrue($supervisor->canAccessStore());

        $this->assertTrue($owner->canAccessClub());
        $this->assertTrue($owner->canAccessStore());
    }

    public function test_store_roles_cannot_access_club(): void
    {
        foreach (['store_manager', 'assembler', 'senior_manager'] as $role) {
            $staff = new Admin(['role' => $role]);
            $this->assertFalse($staff->canAccessClub(), $role);
            $this->assertTrue($staff->canAccessStore(), $role);
            $this->assertTrue($staff->isStoreRole(), $role);
            $this->assertSame('admin.store.warehouse', $staff->homeRoute(), $role);
        }
    }

    public function test_location_has_store_for_both_and_store_types(): void
    {
        $clubOnly = new Club(['type' => 'club']);
        $both = new Club(['type' => 'both']);
        $store = new Club(['type' => 'store']);

        $this->assertFalse($clubOnly->hasStore());
        $this->assertTrue($clubOnly->hasClub());

        $this->assertTrue($both->hasStore());
        $this->assertTrue($both->hasClub());

        $this->assertTrue($store->hasStore());
        $this->assertFalse($store->hasClub());
    }

    public function test_permission_matrix_for_store_actions(): void
    {
        $assembler = new Admin(['role' => 'assembler']);
        $manager = new Admin(['role' => 'store_manager']);
        $senior = new Admin(['role' => 'senior_manager']);

        $this->assertFalse($assembler->canManageStoreCatalog());
        $this->assertFalse($assembler->canCancelStoreOrders());
        $this->assertFalse($assembler->canCloseWarranties());

        $this->assertTrue($manager->canManageStoreCatalog());
        $this->assertFalse($manager->canCancelStoreOrders());
        $this->assertFalse($manager->canCloseWarranties());

        $this->assertTrue($senior->canManageStoreCatalog());
        $this->assertTrue($senior->canCancelStoreOrders());
        $this->assertTrue($senior->canCloseWarranties());
    }
}
