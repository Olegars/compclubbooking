<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickApp extends Model
{
    protected $fillable = [
        'title',
        'exe_path',
        'launch_args',
        'sort_order',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'sort_order' => 'integer',
    ];
}
