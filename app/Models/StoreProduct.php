<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreProduct extends Model
{
    public const CATEGORIES = ['component', 'pc', 'peripheral', 'service'];

    protected $fillable = [
        'club_id', 'name', 'sku', 'category', 'price', 'cost',
        'stock', 'serial_tracked', 'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'cost' => 'decimal:2',
        'serial_tracked' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StoreStockMovement::class);
    }
}
