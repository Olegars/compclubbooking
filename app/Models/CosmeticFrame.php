<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CosmeticFrame extends Model
{
    protected $fillable = [
        'club_id', 'slug', 'name', 'image_path', 'min_level', 'unlock_achievement_code', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'min_level' => 'integer'];
    }
}
