<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderKitchenPrint extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_PRINTED = 'printed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'order_id',
        'status',
        'payload_text',
        'attempts',
        'claimed_at',
        'printed_at',
        'last_error',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'claimed_at' => 'datetime',
        'printed_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
