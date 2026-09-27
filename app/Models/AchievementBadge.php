<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AchievementBadge extends Model
{
    protected $fillable = [
        'club_id', 'slug', 'name', 'image_path', 'source_kind', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
