<?php

namespace App\Console\Commands;

use App\Models\StoreAvitoSetting;
use App\Services\StoreAvito\StoreAvitoMessengerService;
use Illuminate\Console\Command;

class RefreshStoreAvitoToken extends Command
{
    protected $signature = 'store:refresh-avito-token';

    protected $description = 'Обновить OAuth-токен Avito (client_credentials, живёт около суток)';

    public function handle(StoreAvitoMessengerService $messenger): int
    {
        if (! StoreAvitoSetting::hasConfiguredApi()) {
            $this->error('Задайте STORE_AVITO_CLIENT_ID / STORE_AVITO_CLIENT_SECRET в .env.');

            return self::FAILURE;
        }

        $settings = StoreAvitoSetting::current();
        $token = $messenger->accessToken($settings, true);
        $until = $settings->fresh()->access_token_expires_at?->timezone('Europe/Moscow')->format('d.m.Y H:i');
        $this->info('Токен Avito обновлён'.($until ? ', до '.$until : '').' ('.strlen($token).' симв.)');

        return self::SUCCESS;
    }
}
