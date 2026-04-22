<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Computer extends Model
{
    protected $fillable = ['club_id', 'name', 'x', 'y', 'type', 'status'];

    // Связь: компьютер принадлежит конкретному клубу
    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
