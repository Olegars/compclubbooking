<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminRatingEntry extends Model
{
    protected $fillable = [
        'admin_id',
        'event_id',
        'event_name',
        'points',
        'created_by',
    ];

    protected $casts = [
        'points' => 'integer',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(AdminRatingEvent::class, 'event_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
}
