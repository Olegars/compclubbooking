<?php

namespace App\Console\Commands;

use App\Models\StoreAvitoSetting;
use App\Services\StoreAvito\StoreAvitoMessengerService;
use Illuminate\Console\Command;

class SyncStoreAvitoChats extends Command
{
    protected $signature = 'store:sync-avito-chats';

    protected $description = 'Подтянуть чаты Avito и зарегистрировать webhook, если его ещё нет';

    public function handle(StoreAvitoMessengerService $messenger): int
    {
        if (! StoreAvitoSetting::hasConfiguredApi()) {
            $this->error('Задайте STORE_AVITO_CLIENT_ID / STORE_AVITO_CLIENT_SECRET.');

            return self::FAILURE;
        }

        try {
            $url = $messenger->ensureWebhookRegistered();
            $this->info('Webhook: '.$url);
        } catch (\Throwable $e) {
            $this->error('Webhook: '.$e->getMessage());
        }

        try {
            $count = $messenger->syncChats(50);
            $this->info('Чатов из Avito: '.$count);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
