<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalJob extends Model
{
    public const KIND_FISCALIZE = 'fiscalize';

    public const KIND_PRINT_COPY = 'print_copy';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_ERROR = 'error';

    public const STATUS_UNCERTAIN = 'uncertain';

    protected $fillable = [
        'transaction_id',
        'kind',
        'external_id',
        'status',
        'payload',
        'result',
        'attempts',
        'claimed_at',
        'finished_at',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'attempts' => 'integer',
        'claimed_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
