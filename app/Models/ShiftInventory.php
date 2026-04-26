<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftInventory extends Model
{
    // Указываем таблицу вручную, так как Laravel может искать shift_inventories
    protected $table = 'shift_inventory';

    protected $fillable = [
        'shift_id',
        'product_id',
        'expected_stock',
        'actual_stock'
    ];
}
