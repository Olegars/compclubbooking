<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SharedFanLink extends Model
{
    protected $fillable = [
        'shared_fan_id',
        'space_fan_id',
    ];

    protected $casts = [
        'shared_fan_id' => 'integer',
        'space_fan_id' => 'integer',
    ];

    public function sharedFan(): BelongsTo
    {
        return $this->belongsTo(SharedFan::class);
    }

    public function spaceFan(): BelongsTo
    {
        return $this->belongsTo(SpaceFan::class);
    }
}
