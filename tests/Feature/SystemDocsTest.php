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

    public function test_pdf_includes_face_pc_spec(): void
    {
        $admin = $this->makeAdmin('supervisor');

        $this->actingAs($admin, 'admin')
            ->get('/admin/docs/pdf?q='.rawurlencode('FACE-01'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/SystemDocsPrint')
                ->where('sections.0.id', 'network')
            );

        $blob = json_encode(\App\Support\SystemDocs::sections(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('ПК лиц: ТЗ железа и место в сети', $blob);
        $this->assertStringContainsString('ПК лиц: софт (метки, лица, ComfyUI)', $blob);
        $this->assertStringContainsString('ПК лиц: подключение к бэкенду', $blob);
        $this->assertStringContainsString('/api/avatar/stylize-targets', $blob);
        $this->assertStringContainsString('--listen 127.0.0.1', $blob);
    }

    public function test_pdf_includes_reactor_ac_plan(): void
    {
        $admin = $this->makeAdmin('supervisor');

        $this->actingAs($admin, 'admin')
            ->get('/admin/docs/pdf?section=anticheat&q='.rawurlencode('REACTOR AC'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/SystemDocsPrint')
                ->where('section', 'anticheat')
                ->where('sections.0.id', 'anticheat')
                ->where('sections.0.items.0.title', 'Назначение: домашний ПК на сервер клуба')
            );

        $blob = json_encode(\App\Support\SystemDocs::sections(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Античит домашней игры (REACTOR AC)', $blob);
        $this->assertStringContainsString('/api/ac/connect-token', $blob);
        $this->assertStringContainsString('CounterStrikeSharp', $blob);
        $this->assertStringContainsString('Protocol Gate', $blob);
        $this->assertStringContainsString('match_making', $blob);
        $this->assertStringContainsString('R&D / Conditional', $blob);
        $this->assertStringContainsString('ReactorAcSvc', $blob);
        $this->assertStringNotContainsString('ReactorAcWatchdog', $blob);
        $this->assertStringNotContainsString('assertPlayer($user)', $blob);
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
