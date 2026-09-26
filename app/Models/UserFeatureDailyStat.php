<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserFeatureDailyStat extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'date',
        'club_id',
        'feature_key',
        'source_client',
        'total_actions',
        'unique_users',
        'unique_stations',
    ];

    protected $casts = [
        'date' => 'date',
        'total_actions' => 'integer',
        'unique_users' => 'integer',
        'unique_stations' => 'integer',
    ];
}
