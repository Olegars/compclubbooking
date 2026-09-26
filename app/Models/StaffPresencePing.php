<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffPresencePing extends Model
{
    protected $fillable = [
        'admin_id',
        'seen_at',
        'source',
    ];

    protected $casts = [
        'seen_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
