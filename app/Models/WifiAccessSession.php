<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WifiAccessSession extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_GRANTED = 'granted';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'user_id',
        'phone',
        'station_code',
        'mac_address',
        'client_ip',
        'status',
        'authorized_at',
        'granted_at',
        'expires_at',
        'revoked_at',
        'user_agent',
    ];

    protected $casts = [
        'authorized_at' => 'datetime',
        'granted_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        if (! in_array($this->status, [self::STATUS_PENDING, self::STATUS_GRANTED], true)) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
