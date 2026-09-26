<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffSfrEvent extends Model
{
    protected $fillable = [
        'incident_id',
        'admin_id',
        'event_code',
        'reason_code',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(StaffDisciplinaryIncident::class, 'incident_id');
    }
}
