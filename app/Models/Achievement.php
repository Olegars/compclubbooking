<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Achievement extends Model
{
    public const TYPE_PLAY_HOURS = 'play_hours';
    public const TYPE_NIGHT_VISITS = 'night_visits';
    public const TYPE_VISIT_COUNT = 'visit_count';

    public const PERIOD_ONCE = 'once';
    public const PERIOD_WEEKLY = 'weekly';
    public const PERIOD_MONTHLY = 'monthly';

    public const REWARD_DEPOSIT = 'deposit_balance';
    public const REWARD_BONUS = 'bonus_balance';

    protected $fillable = [
        'title',
        'description',
        'type',
        'target_value',
        'period',
        'reward_type',
        'reward_value',
        'night_start',
        'night_end',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'target_value' => 'float',
            'reward_value' => 'float',
            'night_start' => 'integer',
            'night_end' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function userAchievements(): HasMany
    {
        return $this->hasMany(UserAchievement::class);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_PLAY_HOURS => 'Часы игры',
            self::TYPE_NIGHT_VISITS => 'Ночные визиты',
            self::TYPE_VISIT_COUNT => 'Визиты',
            default => $this->type,
        };
    }

    public function periodLabel(): string
    {
        return match ($this->period) {
            self::PERIOD_ONCE => 'Один раз',
            self::PERIOD_WEEKLY => 'Еженедельно',
            self::PERIOD_MONTHLY => 'Ежемесячно',
            default => $this->period,
        };
    }
}
