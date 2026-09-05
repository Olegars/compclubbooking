<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffLedger extends Model
{
    public const TYPE_ACCRUAL = 'accrual';

    public const TYPE_FINE = 'fine';

    public const TYPE_PAYOUT = 'payout';

    protected $fillable = [
        'admin_id',
        'type',
        'amount',
        'reason',
        'shift_id',
        'period_key',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
}
