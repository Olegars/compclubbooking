<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AchievementSourceSetting extends Model
{
    protected $fillable = [
        'club_id', 'gsi_awards', 'steam', 'opendota', 'riot', 'pubg', 'tracker',
        'faceit_skill', 'telegram_ace', 'tracker_city', 'showcase_slots',
        'caffeine_categories', 'food_categories',
    ];

    protected function casts(): array
    {
        return [
            'gsi_awards' => 'boolean',
            'steam' => 'boolean',
            'opendota' => 'boolean',
            'riot' => 'boolean',
            'pubg' => 'boolean',
            'tracker' => 'boolean',
            'faceit_skill' => 'boolean',
            'telegram_ace' => 'boolean',
            'showcase_slots' => 'integer',
            'caffeine_categories' => 'array',
            'food_categories' => 'array',
        ];
    }
}
