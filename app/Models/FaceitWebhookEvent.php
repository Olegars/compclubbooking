<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FaceitWebhookEvent extends Model
{
    protected $fillable = [
        'event_id',
        'event_name',
        'match_id',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
