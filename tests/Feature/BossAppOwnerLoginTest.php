<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BossAppOwnerLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_login_in_boss_app(): void
    {
        $owner = $this->makeAdmin('owner');

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 CompClubBoss/1.0'])
            ->from('/admin/login')
            ->post('/admin/login', [
                'email' => $owner->email,
                'password' => 'password',
            ])
            ->assertRedirect('/admin/dashboard');

        $this->assertAuthenticatedAs($owner, 'admin');
    }

    public function test_floor_admin_cannot_login_in_boss_app(): void
    {
        $admin = $this->makeAdmin('admin');

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 CompClubBoss/1.0'])
            ->from('/admin/login')
            ->post('/admin/login', [
                'email' => $admin->email,
                'password' => 'password',
            ])
            ->assertRedirect('/admin/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest('admin');
    }

    public function test_boss_app_cannot_register_intern(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 CompClubBoss/1.0'])
            ->from('/admin/login')
            ->post('/admin/register', [
                'name' => 'Стажёр',
                'email' => 'intern.boss@test.local',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertRedirect('/admin/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest('admin');
        $this->assertDatabaseMissing('admins', ['email' => 'intern.boss@test.local']);
    }

    public function test_owner_in_boss_app_can_open_admin_docs(): void
    {
        $owner = $this->makeAdmin('owner');

        $this->actingAs($owner, 'admin')
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 CompClubBoss/1.0'])
            ->get('/admin/docs')
            ->assertOk();
    }

    private function makeAdmin(string $role): Admin
    {
        return Admin::query()->create([
            'name' => ucfirst($role),
            'email' => $role.'.'.uniqid().'@boss.test',
            'password' => 'password',
            'role' => $role,
        ]);
    }
}
