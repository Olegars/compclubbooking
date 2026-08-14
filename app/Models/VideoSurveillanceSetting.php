<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoSurveillanceSetting extends Model
{
    protected $fillable = [
        'club_id',
        'is_enabled',
        'provider',
        'api_base_url',
        'api_login',
        'api_secret',
        'marker_duration_sec',
        'marker_pre_sec',
        'default_channel',
        'webhook_path',
        'webhook_method',
        'notes',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'marker_duration_sec' => 'integer',
        'marker_pre_sec' => 'integer',
        'api_secret' => 'encrypted',
    ];

    protected $hidden = [
        'api_secret',
    ];

    public const PROVIDERS = [
        'generic_webhook' => 'HTTP Webhook (универсальный)',
        'hikvision' => 'Hikvision NVR (ISAPI, DS-77xx)',
        'trassir' => 'Trassir (заготовка)',
        'macroscop' => 'Macroscop (заготовка)',
        'custom' => 'Свой адаптер',
    ];

    /** Известные системные триггеры — привязка события в админке */
    public const TRIGGERS = [
        'hid.disconnected' => 'Отключение периферии (мышь/клавиатура)',
        'hid.device_changed' => 'Подмена периферии',
        'hid.unstable' => 'Нестабильная периферия',
        'sos' => 'SOS с терминала',
        'manual' => 'Только вручную / тест',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(VideoSurveillanceEvent::class, 'club_id', 'club_id');
    }

    public static function forClub(?int $clubId = null): self
    {
        $clubId = $clubId ?: (int) Club::query()->value('id');

        return static::query()->firstOrCreate(
            ['club_id' => $clubId],
            [
                'is_enabled' => false,
                'provider' => 'generic_webhook',
                'marker_duration_sec' => 30,
                'marker_pre_sec' => 0,
                'webhook_method' => 'POST',
            ]
        );
    }

    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'club_id' => $this->club_id,
            'is_enabled' => (bool) $this->is_enabled,
            'provider' => $this->provider,
            'api_base_url' => $this->api_base_url,
            'api_login' => $this->api_login,
            'has_api_secret' => filled($this->api_secret),
            'marker_duration_sec' => (int) $this->marker_duration_sec,
            'marker_pre_sec' => (int) $this->marker_pre_sec,
            'default_channel' => $this->default_channel,
            'webhook_path' => $this->webhook_path,
            'webhook_method' => $this->webhook_method ?: 'POST',
            'notes' => $this->notes,
        ];
    }
}
