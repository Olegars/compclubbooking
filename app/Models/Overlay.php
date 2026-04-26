<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Overlay extends Model
{
    protected $fillable = [
        'block_position',
        'title',
        'type',
        'content',
        'is_active'
    ];

    // Автоматическая конвертация JSON в массив при запросах
    protected $casts = [
        'content' => 'array',
        'is_active' => 'boolean',
    ];
}
