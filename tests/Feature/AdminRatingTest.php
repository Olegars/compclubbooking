<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminRatingEntry;
use App\Models\AdminRatingEvent;
use App\Models\Club;
use App\Services\AdminRatingService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_configures_events_and_points_land_in_the_cabinet(): void
    {
        $admin = $this->makeAdmin('admin');
        $supervisor = $this->makeAdmin('supervisor');
        $rating = app(AdminRatingService::class);

        $good = $rating->create('Идеальная смена', 20, AdminRatingEvent::ADD);
        $late = $rating->create('Опоздание', 10, AdminRatingEvent::SUBTRACT);

        $rating->apply($supervisor, $admin, $good);
        $rating->apply($supervisor, $admin, $late);
        $rating->update($good, 'Идеальная смена', 50, AdminRatingEvent::ADD);
        $rating->apply($supervisor, $admin, $good->fresh());

        $this->assertSame(60, $rating->total($admin));
        $this->assertSame(20, AdminRatingEntry::query()->orderBy('id')->value('points'));

        $rating->delete($late);
        $this->assertSame(60, $rating->total($admin));
        $this->assertDatabaseHas('admin_rating_entries', [
            'admin_id' => $admin->id,
            'event_name' => 'Опоздание',
            'points' => -10,
            'event_id' => null,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/salary')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rating.total', 60)
                ->where('rating.history.0.event_name', 'Идеальная смена')
                ->where('rating.history.0.points', 50));
    }

    public function test_rating_page_adds_and_applies_an_event(): void
    {
        $admin = $this->makeAdmin('admin');
        $supervisor = $this->makeAdmin('supervisor');

        $this->actingAs($admin, 'admin')
            ->get('/admin/rating')
            ->assertForbidden();

        $this->actingAs($supervisor, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/rating', [
                'name' => 'Жалоба гостя',
                'points' => 15,
                'direction' => 'subtract',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $eventId = AdminRatingEvent::query()->where('name', 'Жалоба гостя')->value('id');

        $this->actingAs($supervisor, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->put('/admin/rating/'.$eventId, [
                'name' => 'Жалоба гостя',
                'points' => 8,
                'direction' => 'add',
            ])
            ->assertRedirect();

        $this->actingAs($supervisor, 'admin')
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/admin/rating/apply', [
                'admin_id' => $admin->id,
                'event_id' => $eventId,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(8, app(AdminRatingService::class)->total($admin));

        $this->actingAs($supervisor, 'admin')
            ->get('/admin/rating')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('events.0.direction', 'add')
                ->where('events.0.points', 8)
                ->where('admins', fn ($rows) => collect($rows)->contains(
                    fn ($row) => (int) $row['id'] === (int) $admin->id && (int) $row['total'] === 8
                )));
    }

    public function test_event_cannot_be_applied_to_yourself_or_duplicated_by_name(): void
    {
        $supervisor = $this->makeAdmin('supervisor');
        $rating = app(AdminRatingService::class);
        $event = $rating->create('Турнир', 5, AdminRatingEvent::ADD);

        $this->expectException(\RuntimeException::class);
        $rating->apply($supervisor, $supervisor, $event);
    }

    public function test_duplicate_event_name_is_rejected(): void
    {
        $rating = app(AdminRatingService::class);
        $rating->create('Турнир', 5, AdminRatingEvent::ADD);

        $this->expectException(\RuntimeException::class);
        $rating->create('турнир', 5, AdminRatingEvent::SUBTRACT);
    }

    private function makeAdmin(string $role): Admin
    {
        $club = Club::query()->first() ?? Club::query()->create([
            'name' => 'Rating Club',
            'slug' => 'rating-club',
            'type' => 'club',
        ]);

        return Admin::query()->create([
            'name' => ucfirst($role).' '.uniqid(),
            'email' => $role.'.'.uniqid().'@rating.test',
            'password' => 'password',
            'role' => $role,
            'club_id' => $club->id,
            'base_rate' => 2000,
            'pay_type' => 'shift',
            'hired_at' => now(),
        ]);
    }
}
