<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StoreAvitoSetting extends Model
{
    public const CONFIG_PHRASE = 'Для получения текущего списка комплектующих для данной конфигурации (ID:%s) запросите в чате';

    protected $fillable = [
        'enabled', 'ads_per_hour', 'keep_active',
        'address', 'contact_phone', 'manager_name', 'pc_type',
        'markup_percent', 'extra_rub', 'round_to',
        'discount_over_60k_pct', 'discount_over_100k_pct',
        'client_id', 'client_secret', 'access_token', 'refresh_token',
        'access_token_expires_at', 'avito_user_id', 'feed_token',
        'auto_reply_enabled', 'auto_reply_from', 'auto_reply_to', 'auto_reply_text',
        'last_generated_at', 'last_generate_result', 'last_error',
    ];

    protected $hidden = [
        'client_secret', 'access_token', 'refresh_token',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'auto_reply_enabled' => 'boolean',
        'markup_percent' => 'decimal:2',
        'extra_rub' => 'decimal:2',
        'discount_over_60k_pct' => 'decimal:2',
        'discount_over_100k_pct' => 'decimal:2',
        'client_secret' => 'encrypted',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'access_token_expires_at' => 'datetime',
        'last_generated_at' => 'datetime',
        'last_generate_result' => 'array',
    ];

    public static function current(): self
    {
        $row = self::query()->orderBy('id')->first();
        if ($row) {
            if (! filled($row->feed_token)) {
                $row->forceFill(['feed_token' => Str::random(40)])->save();
            }

            return $row;
        }

        return self::query()->create([
            'feed_token' => Str::random(40),
            'auto_reply_text' => 'Здравствуйте. Спасибо, что обратились к нам. Магазин работает с 10:00 по московскому времени. После 10 часов обязательно ответим.',
        ]);
    }

    public function hasMessenger(): bool
    {
        return filled($this->client_id)
            && filled($this->client_secret)
            && (int) $this->avito_user_id > 0;
    }

    public static function configPhrase(string $configId): string
    {
        return sprintf(self::CONFIG_PHRASE, $configId);
    }
}
