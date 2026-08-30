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
    ];

    protected $casts = [
        'payload' => 'array',
        'light_brightness' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
