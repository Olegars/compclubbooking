<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Payment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_WAITING = 'waiting_for_capture';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'uuid',
        'user_id',
        'amount',
        'currency',
        'status',
        'provider',
        'provider_payment_id',
        'confirmation_url',
        'method',
        'idempotency_key',
        'transaction_id',
        'payload',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'payload' => 'array',
        'paid_at' => 'datetime',
    ];

    protected static function booting(): void
    {
        static::creating(function (Payment $payment) {
            if (!$payment->uuid) {
                $payment->uuid = (string) Str::uuid();
            }
            if (!$payment->idempotency_key) {
                $payment->idempotency_key = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function isSucceeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCEEDED, self::STATUS_CANCELED], true);
    }
}
