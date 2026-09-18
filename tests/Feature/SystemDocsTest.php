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
        $this->assertStringContainsString('ПК лиц: VRAM и три службы', $blob);
        $this->assertStringContainsString('/api/avatar/stylize-targets', $blob);
        $this->assertStringContainsString('--listen 127.0.0.1', $blob);
        $this->assertStringContainsString('--lowvram', $blob);
        $this->assertStringContainsString('club-face-agent', $blob);
        $this->assertStringContainsString('gpu_mem_limit', $blob);
        $this->assertStringContainsString('OpenVINOExecutionProvider', $blob);
        $this->assertStringContainsString('CPUExecutionProvider', $blob);
        $this->assertStringContainsString('ctx_id=-1', $blob);
        $this->assertStringContainsString('Channels/102', $blob);
        $this->assertStringContainsString('SDXL Lightning', $blob);
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
        $this->assertStringContainsString('ReactorAcGatePlugin', $blob);
        $this->assertStringContainsString('OnClientAuthorized', $blob);
        $this->assertStringContainsString('heartbeat-check', $blob);
        $this->assertStringContainsString('token_connect_ttl', $blob);
        $this->assertStringContainsString('Переподключиться к матчу', $blob);
        $this->assertStringContainsString('_pendingValidation', $blob);
        $this->assertStringContainsString('_activeSessions', $blob);
        $this->assertStringContainsString('AC_TOKEN_CONNECT_TTL', $blob);
        $this->assertStringContainsString('AC_CSS_KEEPALIVE_INTERVAL', $blob);
        $this->assertStringContainsString('HookResult.Handled', $blob);
        $this->assertStringContainsString('validate-station', $blob);
        $this->assertStringContainsString('SSL Pinning', $blob);
        $this->assertStringContainsString('active-sessions', $blob);
        $this->assertStringContainsString('grace cycle', $blob);
        $this->assertStringContainsString('Intermediate', $blob);
        $this->assertStringContainsString('AC_KEEPALIVE_HTTP_GRACE_CYCLES', $blob);
        $this->assertStringContainsString('ReactorAcSvc', $blob);
        $this->assertStringNotContainsString('ReactorAcWatchdog', $blob);
        $this->assertStringNotContainsString('assertPlayer($user)', $blob);
    }

    public function test_pdf_includes_faceit_club_spec(): void
    {
        $admin = $this->makeAdmin('supervisor');

        $this->actingAs($admin, 'admin')
            ->get('/admin/docs/pdf?section=faceit&q='.rawurlencode('свой FACEIT'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/SystemDocsPrint')
                ->where('section', 'faceit')
                ->where('sections.0.id', 'faceit')
                ->where('sections.0.items.0.title', 'Назначение: свой FACEIT с ПК зала')
            );

        $blob = json_encode(\App\Support\SystemDocs::sections(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('FACEIT в клубе', $blob);
        $this->assertStringContainsString('open.faceit.com/data/v4', $blob);
        $this->assertStringContainsString('FACEIT Connect', $blob);
        $this->assertStringContainsString('game_player_id', $blob);
        $this->assertStringContainsString('skill_level', $blob);
        $this->assertStringContainsString('match_status_finished', $blob);
        $this->assertStringContainsString('faceit_identities', $blob);
        $this->assertStringContainsString('FACEIT_CLIENT_SECRET', $blob);
        $this->assertStringContainsString('D:/ShellData/faceit', $blob);
        $this->assertStringContainsString('faceit_player_id', $blob);
        $this->assertStringContainsString('Привязать FACEIT', $blob);
        $this->assertStringContainsString('reactor:sync-faceit', $blob);
        $this->assertStringContainsString('/api/faceit/webhook', $blob);
        $this->assertStringContainsString('App Studio', $blob);
        $this->assertStringContainsString('не пул клуба', $blob);
        $this->assertStringContainsString('FaceitIdentityService', $blob);
        $this->assertStringContainsString('FaceitLfgRankTest', $blob);
        $this->assertStringContainsString('публичного queue API нет', $blob);
        $this->assertStringContainsString('Cyber Cafe IP Whitelist', $blob);
        $this->assertStringContainsString('Source NAT', $blob);
        $this->assertStringContainsString('TPM 2.0', $blob);
        $this->assertStringContainsString('Secure Boot', $blob);
        $this->assertStringContainsString('FACEIT.sys', $blob);
        $this->assertStringContainsString('The service cannot be started', $blob);
        $this->assertStringContainsString('openid', $blob);
        $this->assertStringContainsString('не хранить', $blob);
        $this->assertStringContainsString('reg delete', $blob);
        $this->assertStringContainsString('Ban Evasion', $blob);
        $this->assertStringContainsString('Roaming', $blob);
        $this->assertStringContainsString('Колонок access_token / refresh_token нет', $blob);
        $this->assertStringContainsString('/auth/v1/userinfo', $blob);
        $this->assertStringContainsString('sc query FACEIT', $blob);
        $this->assertStringContainsString('taskkill /F /IM FACEIT.exe /T', $blob);
        $this->assertStringContainsString('Поток данных: ЛК → API → шелл', $blob);
        $this->assertStringContainsString('никакого take()', $blob);
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
