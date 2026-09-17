<?php

namespace Database\Seeders;

use App\Models\Achievement;
use Illuminate\Database\Seeder;

class AchievementSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->quests() as $row) {
            Achievement::query()->firstOrCreate(
                ['type' => $row['type']],
                $row,
            );
        }
    }

    /**
     * Стартовые карточки ЛК: часы за неделю и ночные визиты по 100 ₽ на депозит.
     *
     * @return list<array<string, mixed>>
     */
    private function quests(): array
    {
        return [
            [
                'title' => 'Сыграй 10 часов за неделю',
                'description' => 'Набери 10 часов в клубе за неделю — 100 ₽ на баланс.',
                'type' => Achievement::TYPE_PLAY_HOURS,
                'target_value' => 10,
                'period' => Achievement::PERIOD_WEEKLY,
                'reward_type' => Achievement::REWARD_DEPOSIT,
                'reward_value' => 100,
                'night_start' => 22,
                'night_end' => 6,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'title' => 'Посети клуб 3 ночи',
                'description' => 'Три визита с 22:00 до 06:00 — 100 ₽ на баланс.',
                'type' => Achievement::TYPE_NIGHT_VISITS,
                'target_value' => 3,
                'period' => Achievement::PERIOD_WEEKLY,
                'reward_type' => Achievement::REWARD_DEPOSIT,
                'reward_value' => 100,
                'night_start' => 22,
                'night_end' => 6,
                'is_active' => true,
                'sort_order' => 2,
            ],
        ];
    }
}
