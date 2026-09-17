<?php

namespace Tests\Feature;

use App\Models\Achievement;
use Database\Seeders\AchievementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AchievementSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_hours_and_night_visit_quests(): void
    {
        $this->seed(AchievementSeeder::class);

        $this->assertSame(2, Achievement::query()->count());
        $this->assertDatabaseHas('achievements', [
            'type' => Achievement::TYPE_PLAY_HOURS,
            'target_value' => 10,
            'period' => Achievement::PERIOD_WEEKLY,
            'reward_type' => Achievement::REWARD_DEPOSIT,
            'reward_value' => 100,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('achievements', [
            'type' => Achievement::TYPE_NIGHT_VISITS,
            'target_value' => 3,
            'period' => Achievement::PERIOD_WEEKLY,
            'reward_type' => Achievement::REWARD_DEPOSIT,
            'reward_value' => 100,
            'night_start' => 22,
            'night_end' => 6,
            'is_active' => true,
        ]);
    }

    public function test_does_not_duplicate_existing_quest_types(): void
    {
        Achievement::query()->create([
            'title' => 'Уже есть',
            'description' => '',
            'type' => Achievement::TYPE_PLAY_HOURS,
            'target_value' => 5,
            'period' => Achievement::PERIOD_ONCE,
            'reward_type' => Achievement::REWARD_DEPOSIT,
            'reward_value' => 50,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->seed(AchievementSeeder::class);

        $this->assertSame(1, Achievement::query()->where('type', Achievement::TYPE_PLAY_HOURS)->count());
        $this->assertDatabaseHas('achievements', [
            'type' => Achievement::TYPE_NIGHT_VISITS,
            'target_value' => 3,
        ]);
    }
}
