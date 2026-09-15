<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSetting extends Model
{
    protected $fillable = [
        'user_id',
        'payload',
        'tts_voice',
        'light_color',
        'light_brightness',
        'light_effect',
        'light_interactive',
    ];

    protected $casts = [
        'payload' => 'array',
        'light_brightness' => 'integer',
        'light_interactive' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
