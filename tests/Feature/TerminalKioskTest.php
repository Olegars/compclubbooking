<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TerminalKioskTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminal_page_is_the_kiosk_booking_screen(): void
    {
        DB::table('clubs')->insert([
            'name' => 'Reactor',
            'slug' => 'center',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/terminal/center?start=18:00&dur=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Booking/BookingView')
                ->where('isTerminal', true)
                ->where('preselectSeatIds', [])
                ->where('preselectStart', '18:00')
                ->where('preselectDuration', 2.0));
    }

    public function test_pin_login_returns_to_the_terminal(): void
    {
        User::create([
            'name' => 'Guest',
            'phone' => '+79990001122',
            'email' => 'kiosk@example.test',
            'password' => 'password',
        ]);

        $this->post('/auth/verify-code', [
            'phone' => '+79990001122',
            'code' => '0451',
            'redirect_to' => '/terminal/center?seat=4&start=18:00&dur=2',
        ])->assertRedirect('/terminal/center?seat=4&start=18:00&dur=2');
    }

    public function test_logout_from_the_terminal_stays_on_the_kiosk(): void
    {
        $user = User::create([
            'name' => 'Guest',
            'phone' => '+79990001123',
            'email' => 'kiosk2@example.test',
            'password' => 'password',
        ]);

        $this->actingAs($user)
            ->post('/logout', ['redirect_to' => '/terminal/center'])
            ->assertRedirect('/terminal/center');

        $this->assertGuest();
    }

    public function test_logout_rejects_a_redirect_off_the_terminal(): void
    {
        $user = User::create([
            'name' => 'Guest',
            'phone' => '+79990001124',
            'email' => 'kiosk3@example.test',
            'password' => 'password',
        ]);

        $this->actingAs($user)
            ->post('/logout', ['redirect_to' => 'https://evil.test/terminal'])
            ->assertRedirect(route('home'));
    }
}
