<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Club;
use App\Models\Overlay;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OverlayAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_seeds_six_idle_slots(): void
    {
        $this->assertSame(6, Overlay::query()->count());
        $this->assertEqualsCanonicalizing(
            array_keys(Overlay::SLOTS),
            Overlay::query()->pluck('block_position')->all()
        );
        $this->assertSame(0, Overlay::query()->where('is_active', true)->count());
    }

    public function test_admin_overlays_api_creates_slots_when_table_is_empty(): void
    {
        Overlay::query()->delete();
        $this->assertSame(0, Overlay::query()->count());

        $this->actingAs($this->owner(), 'admin')
            ->getJson('/admin/api/overlays')
            ->assertOk()
            ->assertJsonCount(6);

        $this->assertSame(6, Overlay::query()->count());

        $this->actingAs($this->owner(), 'admin')
            ->getJson('/admin/api/overlays')
            ->assertOk()
            ->assertJsonCount(6);

        $this->assertSame(6, Overlay::query()->count());
    }

    public function test_active_video_overlay_is_served_to_shell(): void
    {
        Event::fake();
        $slot = Overlay::query()->where('block_position', 'top_left')->first();
        $this->assertNotNull($slot);

        $this->actingAs($this->owner(), 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->putJson('/admin/api/overlays/'.$slot->id, [
                'title' => $slot->title,
                'type' => 'video',
                'content' => [
                    'layers' => [
                        ['type' => 'video', 'value' => 'storage/videos/demo.mp4'],
                    ],
                ],
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->getJson('/api/shell/overlays')
            ->assertOk()
            ->assertJsonPath('data.top_left.content.layers.0.type', 'video')
            ->assertJsonPath('data.top_left.content.layers.0.value', 'storage/videos/demo.mp4')
            ->assertJsonPath('data.top_left.is_active', true);
    }

    public function test_inactive_video_overlay_is_hidden_from_shell(): void
    {
        $slot = Overlay::query()->where('block_position', 'top_left')->first();
        $this->assertNotNull($slot);
        $slot->update([
            'is_active' => false,
            'content' => ['layers' => [['type' => 'video', 'value' => 'storage/videos/demo.mp4']]],
        ]);

        $this->getJson('/api/shell/overlays')
            ->assertOk()
            ->assertJsonMissingPath('data.top_left');
    }

    public function test_admin_overlays_page_includes_slots(): void
    {
        Overlay::query()->delete();

        $this->actingAs($this->owner(), 'admin')
            ->get('/admin/overlays')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/OverlayManager')
                ->has('overlays', 6)
            );
    }

    private function owner(): Admin
    {
        Club::query()->create([
            'name' => '0451',
            'slug' => 'club-ov-'.uniqid(),
            'type' => 'club',
        ]);

        return Admin::query()->create([
            'name' => 'Owner',
            'email' => 'owner.'.uniqid().'@overlays.test',
            'password' => 'password',
            'role' => 'owner',
            'club_id' => null,
        ]);
    }
}
