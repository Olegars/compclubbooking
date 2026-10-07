<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\Club;
use App\Models\Computer;
use App\Models\User;
use App\Models\UserSetting;
use App\Models\Wallet;
use App\Services\ClubFeatureService;
use App\Services\UserCloudSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevicePrefsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Club $club;

    private Computer $pc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create([
            'name' => 'Device Guest',
            'phone' => '+79992220001',
            'email' => 'devices@example.test',
            'password' => 'password',
            'balance' => 2000,
        ]);
        Wallet::create([
            'user_id' => $this->user->id,
            'deposit_balance' => 2000,
            'bonus_balance' => 0,
        ]);
        $this->club = Club::create(['name' => 'Device Club', 'slug' => 'device-club']);
        $this->pc = Computer::create([
            'club_id' => $this->club->id,
            'name' => 'ПК-01',
            'status' => 'available',
            'kind' => 'pc',
        ]);
    }

    public function test_login_returns_device_prefs_and_logout_updates_them(): void
    {
        app(UserCloudSettingsService::class)->saveDevicePrefs($this->user, [
            'mouse_speed' => 14,
            'mouse_accel' => false,
            'keyboard_color' => '#22C55E',
            'keyboard_brightness' => 40,
        ]);

        $this->makeBooking('1234');
        $this->postJson('/api/shell/login', [
            'phone' => $this->user->phone,
            'pin' => '1234',
            'terminal_id' => $this->pc->id,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('device_prefs.mouse_speed', 14)
            ->assertJsonPath('device_prefs.mouse_accel', false)
            ->assertJsonPath('device_prefs.keyboard_color', '#22c55e')
            ->assertJsonPath('device_prefs.keyboard_brightness', 40);

        $this->postJson('/api/shell/device-prefs', [
            'terminal_id' => $this->pc->id,
            'device_prefs' => ['mouse_speed' => 18, 'mouse_accel' => true],
        ])->assertOk()->assertJsonPath('status', 'success')
            ->assertJsonPath('device_prefs.mouse_speed', 18)
            ->assertJsonPath('device_prefs.keyboard_color', '#22c55e');

        $this->postJson('/api/shell/logout', [
            'terminal_id' => $this->pc->id,
            'device_prefs' => [
                'mouse_speed' => 6,
                'mouse_accel' => false,
                'keyboard_color' => 'off',
            ],
        ])->assertOk()->assertJsonPath('status', 'success');

        $row = UserSetting::query()->where('user_id', $this->user->id)->first();
        $this->assertSame(6, $row->device_prefs['mouse_speed']);
        $this->assertFalse($row->device_prefs['mouse_accel']);
        $this->assertNull($row->device_prefs['keyboard_color']);
        $this->assertSame(40, $row->device_prefs['keyboard_brightness']);
    }

    public function test_feature_off_hides_and_does_not_overwrite_prefs(): void
    {
        app(UserCloudSettingsService::class)->saveDevicePrefs($this->user, [
            'mouse_speed' => 12,
            'mouse_accel' => true,
        ]);
        app(ClubFeatureService::class)->save($this->club->id, 'cloud_saves', false);

        $this->makeBooking('4321');
        $this->postJson('/api/shell/login', [
            'phone' => $this->user->phone,
            'pin' => '4321',
            'terminal_id' => $this->pc->id,
        ])->assertOk()->assertJsonPath('device_prefs', null);

        $this->postJson('/api/shell/device-prefs', [
            'terminal_id' => $this->pc->id,
            'device_prefs' => ['mouse_speed' => 3],
        ])->assertOk()->assertJsonPath('status', 'error');

        $this->postJson('/api/shell/logout', [
            'terminal_id' => $this->pc->id,
            'device_prefs' => ['mouse_speed' => 3, 'mouse_accel' => false],
        ])->assertOk();

        $row = UserSetting::query()->where('user_id', $this->user->id)->first();
        $this->assertSame(12, $row->device_prefs['mouse_speed']);
        $this->assertTrue($row->device_prefs['mouse_accel']);
    }

    public function test_bad_keyboard_color_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(UserCloudSettingsService::class)->saveDevicePrefs($this->user, [
            'keyboard_color' => 'red',
        ]);
    }

    private function makeBooking(string $pin): Booking
    {
        $now = CarbonImmutable::now();
        $ends = $now->addHour();
        $group = BookingGroup::create([
            'user_id' => $this->user->id,
            'club_id' => $this->club->id,
            'starts_at' => $now,
            'ends_at' => $ends,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'currency' => 'RUB',
            'computers_total_minor' => 30000,
            'games_total_minor' => 0,
            'total_minor' => 30000,
            'paid_total_minor' => 30000,
            'paid_at' => $now->subHour(),
        ]);

        return Booking::create([
            'booking_group_id' => $group->id,
            'user_id' => $this->user->id,
            'computer_id' => $this->pc->id,
            'pc_ids' => [(string) $this->pc->id],
            'date' => $now->toDateString(),
            'start_time' => $now->hour + $now->minute / 60 + $now->second / 3600,
            'duration' => 1,
            'price' => 300,
            'price_minor' => 30000,
            'status' => 'confirmed',
            'pin_code' => $pin,
            'starts_at' => $now,
            'ends_at' => $ends,
        ]);
    }
}
