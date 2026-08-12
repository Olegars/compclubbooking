<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorePosPrint extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_PRINTED = 'printed';

    public const STATUS_FAILED = 'failed';

    public const KIND_BARCODE = 'barcode';

    public const KIND_QR = 'qr';

    /** Agent job id offset so store jobs never collide with kitchen order_kitchen_prints ids. */
    public const AGENT_ID_OFFSET = 1_000_000_000;

    protected $fillable = [
        'club_id', 'store_warranty_id', 'kind', 'serial', 'payload_text',
        'status', 'attempts', 'claimed_at', 'printed_at', 'last_error',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
        'printed_at' => 'datetime',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function warranty(): BelongsTo
    {
        return $this->belongsTo(StoreWarranty::class, 'store_warranty_id');
    }

    public static function toAgentId(int $id): int
    {
        return self::AGENT_ID_OFFSET + $id;
    }

    public static function fromAgentId(int $agentId): ?int
    {
        if ($agentId < self::AGENT_ID_OFFSET) {
            return null;
        }

        return $agentId - self::AGENT_ID_OFFSET;
    }
}
