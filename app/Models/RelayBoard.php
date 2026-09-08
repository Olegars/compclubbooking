<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RelayBoard extends Model
{
    public const DRIVER_W5100_HTTP = 'w5100_http';

    /** HW-584 with NetMod-ServerApp: http://{host}:{port}/{cmd} (default TCP 8080). */
    public const DRIVER_NETMOD_HTTP = 'netmod_http';

    public const DRIVER_KINCONY_HTTP = 'kincony_http';

    public const DRIVER_DINGTIAN_HTTP = 'dingtian_http';

    protected $fillable = [
        'club_id',
        'name',
        'driver',
        'host',
        'port',
        'meta',
        'is_active',
    ];

    protected $casts = [
        'club_id' => 'integer',
        'port' => 'integer',
        'meta' => 'array',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'http_base',
    ];

    public function getHttpBaseAttribute(): string
    {
        return self::httpBase((string) $this->host, $this->port, $this->driver);
    }

    public static function fallbackPort(?int $port, ?string $driver = null): int
    {
        if ((int) $port > 0) {
            return (int) $port;
        }

        if ($driver === self::DRIVER_W5100_HTTP) {
            return 30000;
        }

        return (int) config('fan.http_default_port', 8080);
    }

    /** Factory W5100: path segment. NetMod / Kincony / Dingtian: TCP listen port. */
    public static function httpBase(string $host, ?int $port, ?string $driver = null): string
    {
        $p = self::fallbackPort($port, $driver);
        if ($driver === self::DRIVER_W5100_HTTP) {
            return sprintf('http://%s/%d/', $host, $p);
        }
        if ($p === 80) {
            return sprintf('http://%s/', $host);
        }

        return sprintf('http://%s:%d/', $host, $p);
    }

    /** @return list<string> */
    public static function httpDrivers(): array
    {
        return [
            self::DRIVER_NETMOD_HTTP,
            self::DRIVER_W5100_HTTP,
            self::DRIVER_KINCONY_HTTP,
            self::DRIVER_DINGTIAN_HTTP,
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function spaceFans(): HasMany
    {
        return $this->hasMany(SpaceFan::class);
    }

    public function sharedFans(): HasMany
    {
        return $this->hasMany(SharedFan::class);
    }
}
