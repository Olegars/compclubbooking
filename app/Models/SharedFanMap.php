<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SharedFanMap extends Model
{
    protected $fillable = [
        'shared_fan_id',
        'load_pct',
        'output_pct',
    ];

    protected $casts = [
        'shared_fan_id' => 'integer',
        'load_pct' => 'integer',
        'output_pct' => 'integer',
    ];

    public function sharedFan(): BelongsTo
    {
        return $this->belongsTo(SharedFan::class);
    }
}
