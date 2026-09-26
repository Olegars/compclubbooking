<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffBonusSettlement extends Model
{
    public const MONTHLY = 'monthly_advance';

    public const QUARTERLY = 'quarterly_final';

    public const APPROVED = 'approved';

    public const BURNED = 'burned';

    protected $fillable = [
        'admin_id',
        'club_id',
        'period_type',
        'period_label',
        'total_xp',
        'rate',
        'calculated_rub',
        'paid_rub',
        'held_rub',
        'status',
        'approved_by',
        'ledger_id',
        'paid_at',
    ];

    protected $casts = [
        'total_xp' => 'integer',
        'rate' => 'decimal:2',
        'calculated_rub' => 'decimal:2',
        'paid_rub' => 'decimal:2',
        'held_rub' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(StaffLedger::class, 'ledger_id');
    }
}
