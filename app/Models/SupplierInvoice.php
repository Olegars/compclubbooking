<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierInvoice extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'supplier_id',
        'number',
        'issued_at',
        'due_at',
        'total_amount',
        'paid_amount',
        'status',
        'notes',
        'admin_id',
    ];

    protected $casts = [
        'issued_at' => 'date',
        'due_at' => 'date',
        'total_amount' => 'float',
        'paid_amount' => 'float',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function balanceDue(): float
    {
        return max(0, round((float) $this->total_amount - (float) $this->paid_amount, 2));
    }

    public function refreshStatus(): void
    {
        if ($this->status === self::STATUS_CANCELLED) {
            return;
        }

        $due = $this->balanceDue();
        if ($due <= 0.009) {
            $this->status = self::STATUS_PAID;
        } elseif ((float) $this->paid_amount > 0) {
            $this->status = self::STATUS_PARTIAL;
        } else {
            $this->status = self::STATUS_OPEN;
        }
        $this->save();
    }

    public function isOverdue(): bool
    {
        if (! in_array($this->status, [self::STATUS_OPEN, self::STATUS_PARTIAL], true)) {
            return false;
        }

        return $this->due_at && $this->due_at->isPast() && $this->balanceDue() > 0;
    }
}
