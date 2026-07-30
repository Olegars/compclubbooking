<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReservation extends Model
{
    protected $fillable = [
        'order_id',
        'product_id',
        'qty',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'product_id' => 'integer',
        'qty' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
