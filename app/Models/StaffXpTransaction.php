<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffXpTransaction extends Model
{
    public const SHIFT_BASE = 'shift_base';

    public const BAR_PERFECT = 'bar_perfect';

    public const HARDWARE_GREEN = 'hardware_green';

    public const BAR_PLAN = 'bar_plan';

    public const SHORTAGE = 'shortage';

    public const LATE_SHIFT = 'late_shift';

    public const MANUAL = 'manual';

    public const CARRY = 'carry';

    public const POOL_RESET = 'pool_reset';

    protected $fillable = [
        'admin_id',
        'club_id',
        'shift_id',
        'amount_xp',
        'action_type',
        'description',
        'period_key',
        'settlement_id',
        'created_by',
    ];

    protected $casts = [
        'amount_xp' => 'integer',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
