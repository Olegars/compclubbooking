<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shift extends Model
{
    protected $fillable = [
        'admin_id',
        'closed_by',
        'incoming_admin_id',
        'started_at',
        'ended_at',
        'transfer_started_at',
        'presence_verified_at',
        'presence_meta',
        'cash_start',
        'cash_end',
        'status',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'transfer_started_at' => 'datetime',
        'presence_verified_at' => 'datetime',
        'presence_meta' => 'array',
        'cash_start' => 'decimal:2',
        'cash_end' => 'decimal:2',
    ];

    // Связь с админом, который открыл смену
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public function closedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'closed_by');
    }

    public function incomingAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'incoming_admin_id');
    }

    public function ledgerAccrual(): HasOne
    {
        return $this->hasOne(StaffLedger::class, 'shift_id')
            ->where('type', StaffLedger::TYPE_ACCRUAL)
            ->whereColumn('staff_ledgers.admin_id', 'shifts.admin_id');
    }

    public function internSlots(): HasMany
    {
        return $this->hasMany(ShiftIntern::class);
    }

    public function activeInterns(): HasMany
    {
        return $this->hasMany(ShiftIntern::class)->whereNull('left_at');
    }

    // Связь с записями инвентаризации этой смены
    public function inventoryItems(): HasMany
    {
        return $this->hasMany(ShiftInventory::class);
    }
}
