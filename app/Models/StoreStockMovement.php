<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreStockMovement extends Model
{
    protected $fillable = [
        'club_id', 'store_product_id', 'admin_id', 'type', 'qty', 'stock_after', 'reason',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(StoreProduct::class, 'store_product_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
