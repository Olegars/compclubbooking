<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreClient extends Model
{
    protected $fillable = [
        'club_id', 'name', 'phone', 'email', 'notes',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(StoreOrder::class);
    }

    public function warranties(): HasMany
    {
        return $this->hasMany(StoreWarranty::class);
    }
}
