<?php

namespace App\Services\StoreAvito;

use App\Models\StoreAvitoAd;
use App\Models\StoreAvitoChat;
use App\Models\StoreAvitoMessage;
use App\Models\StoreAvitoSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StoreAvitoMessengerService
{
    public function __construct(private readonly StoreAvitoPricer $pricer) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload): void
    {
        $value = data_get($payload, 'payload.value', data_get($payload, 'value', $payload));
        if (! is_array($value) || empty($value['chat_id'])) {
            return;
        }

        $settings = StoreAvitoSetting::current();
        $chatId = (string) $value['chat_id'];
        $authorId = isset($value['author_id']) ? (int) $value['author_id'] : null;
        $fromUs = $authorId !== null && (int) $settings->avito_user_id > 0 && $authorId === (int) $settings->avito_user_id;

        $chat = StoreAvitoChat::query()->where('chat_id', $chatId)->first();
        $isNew = ! $chat;
        if (! $chat) {
            $chat = StoreAvitoChat::query()->create([
                'chat_id' => $chatId,
                'avito_user_id' => $value['user_id'] ?? $settings->avito_user_id,
                'unread' => ! $fromUs,
                'last_message_at' => now(),
            ]);
            $this->hydrateChat($chat, $settings);
        } else {
            $chat->forceFill([
                'unread' => $fromUs ? $chat->unread : true,
                'last_message_at' => now(),
            ])->save();
        }

        $text = (string) data_get($value, 'content.text', '');
        $configId = $this->extractConfigId($text) ?: $this->extractConfigId((string) $chat->ad_title);
        if ($configId && ! $chat->config_id) {
            $chat->forceFill(['config_id' => $configId])->save();
        }

        StoreAvitoMessage::query()->create([
            'chat_id' => $chatId,
            'avito_message_id' => isset($value['id']) ? (string) $value['id'] : null,
            'author_id' => $authorId,
            'type' => (string) ($value['type'] ?? 'text'),
            'content' => is_array($value['content'] ?? null) ? $value['content'] : ['text' => $text],
            'from_us' => $fromUs,
            'read' => $fromUs,
            'avito_created_at' => isset($value['created']) ? Carbon::createFromTimestamp((int) $value['created']) : now(),
        ]);

        if ($fromUs) {
            return;
        }

        if ($isNew && $this->shouldAutoReply($settings)) {
            $this->sendText($chatId, (string) $settings->auto_reply_text);
        }

        $lookupId = $configId ?: $this->extractConfigId($text);
        if ($lookupId && $this->wantsBom($text, $isNew)) {
            $reply = $this->bomReply($lookupId);
            if ($reply !== null) {
                $this->sendText($chatId, $reply);
            }
        }
    }

    public function sendText(string $chatId, string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        $settings = StoreAvitoSetting::current();
        if (! $settings->hasMessenger()) {
            StoreAvitoMessage::query()->create([
                'chat_id' => $chatId,
                'type' => 'text',
                'content' => ['text' => $text],
                'from_us' => true,
                'read' => true,
                'avito_created_at' => now(),
            ]);
            StoreAvitoChat::query()->where('chat_id', $chatId)->update(['last_message_at' => now()]);

            return true;
        }

        try {
            $token = $this->accessToken($settings);
            $userId = (int) $settings->avito_user_id;
            $response = Http::timeout(20)
                ->withToken($token)
                ->acceptJson()
                ->post("https://api.avito.ru/messenger/v1/accounts/{$userId}/chats/{$chatId}/messages", [
                    'type' => 'text',
                    'message' => ['text' => $text],
                ]);
            if (! $response->successful()) {
                Log::warning('Avito send message: HTTP '.$response->status().' '.$response->body());

                return false;
            }
        } catch (\Throwable $e) {
            Log::warning('Avito send message: '.$e->getMessage());

            return false;
        }

        StoreAvitoMessage::query()->create([
            'chat_id' => $chatId,
            'type' => 'text',
            'content' => ['text' => $text],
            'from_us' => true,
            'read' => true,
            'avito_created_at' => now(),
        ]);
        StoreAvitoChat::query()->where('chat_id', $chatId)->update([
            'last_message_at' => now(),
            'unread' => false,
        ]);

        return true;
    }

    public function registerWebhook(string $url): void
    {
        $settings = StoreAvitoSetting::current();
        $token = $this->accessToken($settings);
        $userId = (int) $settings->avito_user_id;
        $response = Http::timeout(20)
            ->withToken($token)
            ->acceptJson()
            ->post("https://api.avito.ru/messenger/v1/accounts/{$userId}/webhook", [
                'url' => $url,
            ]);
        if (! $response->successful()) {
            throw new \RuntimeException('Avito webhook: HTTP '.$response->status().' '.$response->body());
        }
    }

    public function accessToken(?StoreAvitoSetting $settings = null): string
    {
        $settings ??= StoreAvitoSetting::current();
        if (filled($settings->access_token) && $settings->access_token_expires_at && $settings->access_token_expires_at->isFuture()) {
            return (string) $settings->access_token;
        }
        if (! filled($settings->client_id) || ! filled($settings->client_secret)) {
            throw new \RuntimeException('Avito client_id / client_secret не заданы.');
        }

        $response = Http::asForm()->timeout(20)->post('https://api.avito.ru/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $settings->client_id,
            'client_secret' => $settings->client_secret,
        ]);
        if (! $response->successful()) {
            throw new \RuntimeException('Avito token: HTTP '.$response->status().' '.$response->body());
        }
        $token = (string) $response->json('access_token');
        $expires = (int) ($response->json('expires_in') ?: 86400);
        $settings->forceFill([
            'access_token' => $token,
            'refresh_token' => $response->json('refresh_token') ?: $settings->refresh_token,
            'access_token_expires_at' => now()->addSeconds(max(60, $expires - 120)),
        ])->save();

        return $token;
    }

    public function bomReply(string $configId): ?string
    {
        $ad = StoreAvitoAd::query()->where('config_id', $configId)->first();
        if (! $ad) {
            return null;
        }

        $settings = StoreAvitoSetting::current();
        $parts = is_array($ad->components) ? $ad->components : [];
        $live = [];
        foreach ($parts as $row) {
            $sku = (int) ($row['sku'] ?? 0);
            $purchase = (float) ($row['purchase'] ?? 0);
            if ($sku > 0) {
                $price = \App\Models\StoreSupplierCatalogProduct::query()->where('sku', $sku)->value('price');
                if ($price !== null) {
                    $purchase = (float) $price;
                }
            }
            $live[] = [
                'type' => $row['type'] ?? '',
                'name' => $row['name'] ?? '',
                'purchase' => $purchase,
                'sale' => $this->pricer->saleOf($purchase, $settings),
            ];
        }
        $total = $this->pricer->quote($live, $settings);

        $lines = ['Конфигурация ID:'.$configId, 'Актуально на '.now('Europe/Moscow')->format('d.m.Y H:i'), ''];
        foreach ($live as $row) {
            $lines[] = '• '.$row['name'].' — '.number_format((float) $row['sale'], 0, '', ' ').' ₽';
        }
        $lines[] = '';
        $lines[] = 'Итого: '.number_format($total, 0, '', ' ').' ₽';
        $lines[] = 'Корпус в цене (можно выбрать другой).';

        return implode("\n", $lines);
    }

    public function extractConfigId(?string $text): ?string
    {
        $text = strtoupper((string) $text);
        if (preg_match('/\b([A-Z]{3}\d{5})\b/', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    private function wantsBom(string $text, bool $isNew): bool
    {
        $t = mb_strtolower($text);
        if ($this->extractConfigId($text)) {
            return true;
        }
        if ($isNew) {
            return false;
        }

        return str_contains($t, 'комплект')
            || str_contains($t, 'сборк')
            || str_contains($t, 'id')
            || str_contains($t, 'конфиг');
    }

    private function shouldAutoReply(StoreAvitoSetting $settings): bool
    {
        if (! $settings->auto_reply_enabled || ! filled($settings->auto_reply_text)) {
            return false;
        }
        $hour = (int) now('Europe/Moscow')->format('G');
        $from = (int) $settings->auto_reply_from;
        $to = (int) $settings->auto_reply_to;
        if ($from === $to) {
            return false;
        }
        if ($from < $to) {
            return $hour >= $from && $hour < $to;
        }

        return $hour >= $from || $hour < $to;
    }

    private function hydrateChat(StoreAvitoChat $chat, StoreAvitoSetting $settings): void
    {
        if (! $settings->hasMessenger()) {
            return;
        }
        try {
            $token = $this->accessToken($settings);
            $userId = (int) $settings->avito_user_id;
            $response = Http::timeout(20)
                ->withToken($token)
                ->acceptJson()
                ->get("https://api.avito.ru/messenger/v2/accounts/{$userId}/chats/{$chat->chat_id}");
            if (! $response->successful()) {
                return;
            }
            $info = $response->json();
            $client = data_get($info, 'users.0', []);
            $title = (string) data_get($info, 'context.value.title', '');
            $price = preg_replace('/[^\d]/', '', (string) data_get($info, 'context.value.price_string', '')) ?: null;
            $chat->forceFill([
                'client_name' => $client['name'] ?? $chat->client_name,
                'client_id' => $client['id'] ?? $chat->client_id,
                'client_link' => data_get($client, 'public_user_profile.url'),
                'client_avatar' => data_get($client, 'public_user_profile.avatar.images.50x50')
                    ?: data_get($client, 'public_user_profile.avatar.images.48x48'),
                'ad_url' => data_get($info, 'context.value.url'),
                'ad_id' => data_get($info, 'context.value.id'),
                'ad_title' => $title !== '' ? $title : $chat->ad_title,
                'ad_price' => $price ? (int) $price : $chat->ad_price,
                'config_id' => $chat->config_id ?: $this->extractConfigId($title),
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('Avito hydrate chat: '.$e->getMessage());
        }
    }
}
