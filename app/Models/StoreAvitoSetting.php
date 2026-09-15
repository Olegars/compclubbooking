<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        'last_dict_sync_at', 'last_dict_sync_result', 'last_config_id',
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
        'last_dict_sync_at' => 'datetime',
        'last_dict_sync_result' => 'array',
    ];

    public static function current(): self
    {
        $row = self::query()->orderBy('id')->first();
        if ($row) {
            if (! filled($row->feed_token)) {
                $row->forceFill(['feed_token' => Str::random(40)]);
            }
            $row->fillFromEnv();
            if ($row->isDirty()) {
                $row->save();
            }

            return $row;
        }

        $row = self::query()->create([
            'feed_token' => Str::random(40),
            'auto_reply_text' => 'Здравствуйте. Спасибо, что обратились к нам. Магазин работает с 10:00 по московскому времени. После 10 часов обязательно ответим.',
        ]);
        $row->fillFromEnv();
        if ($row->isDirty()) {
            $row->save();
        }

        return $row;
    }

    public function fillFromEnv(): void
    {
        if (! filled($this->client_id) && filled(config('store.avito.client_id'))) {
            $this->client_id = (string) config('store.avito.client_id');
        }
        if (! filled($this->client_secret) && filled(config('store.avito.client_secret'))) {
            $this->client_secret = (string) config('store.avito.client_secret');
        }
        if ((int) $this->avito_user_id < 1 && (int) config('store.avito.user_id') > 0) {
            $this->avito_user_id = (int) config('store.avito.user_id');
        }
    }

    public static function hasConfiguredApi(): bool
    {
        if (filled(config('store.avito.client_id')) && filled(config('store.avito.client_secret'))) {
            return true;
        }
        if (! Schema::hasTable('store_avito_settings')) {
            return false;
        }
        $row = DB::table('store_avito_settings')->orderBy('id')->first();

        return $row && filled($row->client_id) && filled($row->client_secret);
    }

    public function hasAvitoApi(): bool
    {
        return filled($this->client_id) && filled($this->client_secret);
    }

    public function hasMessenger(): bool
    {
        return $this->hasAvitoApi() && (int) $this->avito_user_id > 0;
    }

    public static function configPhrase(string $configId): string
    {
        return sprintf(self::CONFIG_PHRASE, $configId);
    }
}
