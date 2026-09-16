<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemDocsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/admin/docs')->assertRedirect('/admin/login');
        $this->get('/admin/docs/pdf')->assertRedirect('/admin/login');
    }

    public function test_admin_can_open_docs_and_pdf(): void
    {
        $admin = $this->makeAdmin('supervisor');

        $this->actingAs($admin, 'admin')
            ->get('/admin/docs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/SystemDocs')
                ->has('sections')
            );

        $this->actingAs($admin, 'admin')
            ->get('/admin/docs/pdf')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/SystemDocsPrint')
                ->has('sections')
                ->has('printedAt')
                ->where('section', 'all')
                ->where('query', '')
            );
    }

    public function test_pdf_respects_section_and_search(): void
    {
        $admin = $this->makeAdmin('supervisor');

        $this->actingAs($admin, 'admin')
            ->get('/admin/docs/pdf?section=ops&q=дашборд')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/SystemDocsPrint')
                ->where('section', 'ops')
                ->where('query', 'дашборд')
                ->has('sections', 1)
                ->where('sections.0.id', 'ops')
                ->where('sections.0.items.0.title', 'Дашборд')
            );
    }

    public function test_pdf_includes_owner_system_tests(): void
    {
        $admin = $this->makeAdmin('supervisor');

        $this->actingAs($admin, 'admin')
            ->get('/admin/docs/pdf?q=system-tests')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/SystemDocsPrint')
                ->where('query', 'system-tests')
                ->where('sections.0.items.0.title', 'Тесты системы')
            );
    }

    public function test_pdf_includes_ambient_dmx_mirroring(): void
    {
        $admin = $this->makeAdmin('supervisor');

        $this->actingAs($admin, 'admin')
            ->get('/admin/docs/pdf?q=AABB')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/SystemDocsPrint')
                ->where('sections.0.items.0.title', 'Game-Sense Ambient DMX Mirroring')
            );
    }

    private function makeAdmin(string $role): Admin
    {
        return Admin::query()->create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.'.'.uniqid().'@docs.test',
            'password' => 'password',
            'role' => $role,
            'pay_type' => 'shift',
        ]);
    }
}
