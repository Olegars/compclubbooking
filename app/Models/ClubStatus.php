<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClubStatus extends Model
{
    protected $table = 'club_statuses';

    protected $fillable = [
        'club_id', 'key', 'label', 'color', 'priority', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'priority' => 'integer'];
    }
}
