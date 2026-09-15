<?php

namespace App\Services\StoreAvito;

use App\Models\Admin;
use App\Models\StoreAvitoAd;
use App\Models\StoreAvitoChat;
use App\Models\StoreAvitoMessage;
use App\Models\StoreAvitoSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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

        $avitoMessageId = isset($value['id']) ? (string) $value['id'] : null;
        if ($avitoMessageId && StoreAvitoMessage::query()->where('avito_message_id', $avitoMessageId)->exists()) {
            return;
        }

        $chat = StoreAvitoChat::query()->where('chat_id', $chatId)->first();
        $isNew = ! $chat;
        if (! $chat) {
            $chat = StoreAvitoChat::query()->create([
                'chat_id' => $chatId,
                'avito_user_id' => $value['user_id'] ?? $settings->avito_user_id,
                'unread' => ! $fromUs,
                'workflow' => StoreAvitoChat::WORKFLOW_INBOX,
                'last_message_at' => now(),
            ]);
            $this->hydrateChat($chat, $settings);
        } else {
            $updates = [
                'unread' => $fromUs ? $chat->unread : true,
                'last_message_at' => now(),
            ];
            if (! $fromUs && $chat->workflow === StoreAvitoChat::WORKFLOW_DONE) {
                $updates['workflow'] = $chat->accepted_by_id
                    ? StoreAvitoChat::WORKFLOW_IN_PROGRESS
                    : StoreAvitoChat::WORKFLOW_INBOX;
                $updates['done_at'] = null;
            }
            $chat->forceFill($updates)->save();
            if (! filled($chat->client_avatar) || ! filled($chat->client_name)) {
                $this->hydrateChat($chat, $settings);
            }
        }

        $text = (string) data_get($value, 'content.text', '');
        $configId = $this->extractConfigId($text) ?: $this->extractConfigId((string) $chat->ad_title);
        if ($configId && ! $chat->config_id) {
            $chat->forceFill(['config_id' => $configId])->save();
        }

        StoreAvitoMessage::query()->create([
            'chat_id' => $chatId,
            'avito_message_id' => $avitoMessageId,
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

    public function sendText(string $chatId, string $text, ?Admin $admin = null): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        $settings = StoreAvitoSetting::current();
        if (! $settings->hasMessenger()) {
            $this->storeOutgoing($chatId, ['text' => $text], $admin);
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
            $avitoId = $response->json('id');
        } catch (\Throwable $e) {
            Log::warning('Avito send message: '.$e->getMessage());

            return false;
        }

        $this->storeOutgoing(
            $chatId,
            ['text' => $text],
            $admin,
            'text',
            is_string($avitoId) && $avitoId !== '' ? $avitoId : null
        );
        StoreAvitoChat::query()->where('chat_id', $chatId)->update([
            'last_message_at' => now(),
            'unread' => false,
        ]);

        return true;
    }

    public function sendImage(string $chatId, UploadedFile $file, ?Admin $admin = null): bool
    {
        $settings = StoreAvitoSetting::current();
        if (! $settings->hasMessenger()) {
            $path = $file->store('avito-chat', 'public');
            $url = Storage::disk('public')->url($path);
            $this->storeOutgoing($chatId, ['image' => ['sizes' => ['1280x960' => $url]]], $admin, 'image');
            StoreAvitoChat::query()->where('chat_id', $chatId)->update([
                'last_message_at' => now(),
                'unread' => false,
            ]);

            return true;
        }

        try {
            $token = $this->accessToken($settings);
            $userId = (int) $settings->avito_user_id;
            $binary = file_get_contents($file->getRealPath() ?: $file->getPathname());
            if ($binary === false) {
                return false;
            }
            $upload = Http::timeout(40)
                ->withToken($token)
                ->attach(
                    'uploadfile[]',
                    $binary,
                    $file->getClientOriginalName() ?: 'photo.jpg',
                    ['Content-Type' => $file->getMimeType() ?: 'image/jpeg']
                )
                ->post("https://api.avito.ru/messenger/v1/accounts/{$userId}/uploadImages");
            if (! $upload->successful()) {
                Log::warning('Avito upload image: HTTP '.$upload->status().' '.$upload->body());

                return false;
            }
            $body = $upload->json();
            if (! is_array($body) || $body === []) {
                return false;
            }
            $imageId = (string) array_key_first($body);
            $sizes = is_array($body[$imageId] ?? null) ? $body[$imageId] : [];
            $send = Http::timeout(20)
                ->withToken($token)
                ->acceptJson()
                ->post("https://api.avito.ru/messenger/v1/accounts/{$userId}/chats/{$chatId}/messages/image", [
                    'image_id' => $imageId,
                ]);
            if (! $send->successful()) {
                Log::warning('Avito send image: HTTP '.$send->status().' '.$send->body());

                return false;
            }
            $sentJson = $send->json();
            $sentSizes = data_get($sentJson, 'content.image.sizes', $sizes);
            $avitoId = is_array($sentJson) ? ($sentJson['id'] ?? null) : null;
            $this->storeOutgoing(
                $chatId,
                ['image' => ['sizes' => is_array($sentSizes) ? $sentSizes : $sizes]],
                $admin,
                'image',
                is_string($avitoId) && $avitoId !== '' ? $avitoId : null
            );
            StoreAvitoChat::query()->where('chat_id', $chatId)->update([
                'last_message_at' => now(),
                'unread' => false,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Avito send image: '.$e->getMessage());

            return false;
        }
    }

    public function markRead(string $chatId): void
    {
        $settings = StoreAvitoSetting::current();
        if (! $settings->hasMessenger()) {
            return;
        }
        try {
            $token = $this->accessToken($settings);
            $userId = (int) $settings->avito_user_id;
            Http::timeout(15)
                ->withToken($token)
                ->post("https://api.avito.ru/messenger/v1/accounts/{$userId}/chats/{$chatId}/read");
        } catch (\Throwable $e) {
            Log::warning('Avito mark read: '.$e->getMessage());
        }
    }

    public function ensureChatProfile(StoreAvitoChat $chat): void
    {
        if (filled($chat->client_avatar) && filled($chat->client_name)) {
            return;
        }
        $this->hydrateChat($chat, StoreAvitoSetting::current());
    }

    public function syncRecentMessages(StoreAvitoChat $chat): void
    {
        $settings = StoreAvitoSetting::current();
        if (! $settings->hasMessenger()) {
            return;
        }
        try {
            $token = $this->accessToken($settings);
            $userId = (int) $settings->avito_user_id;
            $response = Http::timeout(20)
                ->withToken($token)
                ->acceptJson()
                ->get("https://api.avito.ru/messenger/v3/accounts/{$userId}/chats/{$chat->chat_id}/messages/", [
                    'limit' => 50,
                    'offset' => 0,
                ]);
            if (! $response->successful()) {
                return;
            }
            $json = $response->json();
            $rows = [];
            if (is_array($json)) {
                $rows = array_is_list($json)
                    ? $json
                    : (is_array($json['messages'] ?? null) ? $json['messages'] : []);
            }
            foreach ($rows as $row) {
                if (! is_array($row) || empty($row['id'])) {
                    continue;
                }
                $avitoId = (string) $row['id'];
                if (StoreAvitoMessage::query()->where('avito_message_id', $avitoId)->exists()) {
                    continue;
                }
                $authorId = isset($row['author_id']) ? (int) $row['author_id'] : null;
                $fromUs = ($row['direction'] ?? '') === 'out'
                    || ($authorId !== null && $userId > 0 && $authorId === $userId);
                StoreAvitoMessage::query()->create([
                    'chat_id' => $chat->chat_id,
                    'avito_message_id' => $avitoId,
                    'author_id' => $authorId,
                    'type' => (string) ($row['type'] ?? 'text'),
                    'content' => is_array($row['content'] ?? null) ? $row['content'] : [],
                    'from_us' => $fromUs,
                    'read' => (bool) ($row['is_read'] ?? $fromUs),
                    'avito_created_at' => isset($row['created'])
                        ? Carbon::createFromTimestamp((int) $row['created'])
                        : now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Avito sync messages: '.$e->getMessage());
        }
    }

    /**
     * @param  list<string>  $voiceIds
     * @return array<string, string>
     */
    public function voiceUrls(array $voiceIds): array
    {
        $voiceIds = array_values(array_unique(array_filter($voiceIds)));
        if ($voiceIds === []) {
            return [];
        }
        $settings = StoreAvitoSetting::current();
        if (! $settings->hasMessenger()) {
            return [];
        }
        try {
            $token = $this->accessToken($settings);
            $userId = (int) $settings->avito_user_id;
            $response = Http::timeout(20)
                ->withToken($token)
                ->acceptJson()
                ->get("https://api.avito.ru/messenger/v1/accounts/{$userId}/getVoiceFiles", [
                    'voice_ids' => implode(',', $voiceIds),
                ]);
            if (! $response->successful()) {
                return [];
            }
            $urls = $response->json('voices_urls');
            if (! is_array($urls)) {
                return [];
            }
            $out = [];
            foreach ($urls as $id => $url) {
                if (is_string($url) && $url !== '') {
                    $out[(string) $id] = $url;
                }
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('Avito voice files: '.$e->getMessage());

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function storeOutgoing(string $chatId, array $content, ?Admin $admin, string $type = 'text', ?string $avitoMessageId = null): void
    {
        StoreAvitoMessage::query()->create([
            'chat_id' => $chatId,
            'avito_message_id' => $avitoMessageId,
            'type' => $type,
            'content' => $content,
            'from_us' => true,
            'admin_id' => $admin?->id,
            'read' => true,
            'avito_created_at' => now(),
        ]);
    }

    public function registerWebhook(string $url): void
    {
        $settings = StoreAvitoSetting::current();
        $token = $this->accessToken($settings);
        $response = Http::timeout(20)
            ->withToken($token)
            ->acceptJson()
            ->post('https://api.avito.ru/messenger/v3/webhook', [
                'url' => $url,
            ]);
        if (! $response->successful()) {
            throw new \RuntimeException('Avito webhook: HTTP '.$response->status().' '.$response->body());
        }
    }

    public function accessToken(?StoreAvitoSetting $settings = null, bool $force = false): string
    {
        $settings ??= StoreAvitoSetting::current();
        if (
            ! $force
            && filled($settings->access_token)
            && $settings->access_token_expires_at
            && $settings->access_token_expires_at->isFuture()
        ) {
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
            $users = data_get($info, 'users', []);
            $client = [];
            if (is_array($users)) {
                foreach ($users as $user) {
                    if (! is_array($user)) {
                        continue;
                    }
                    if ((int) ($user['id'] ?? 0) !== $userId) {
                        $client = $user;
                        break;
                    }
                }
                if ($client === [] && isset($users[0]) && is_array($users[0])) {
                    $client = $users[0];
                }
            }
            $title = (string) data_get($info, 'context.value.title', '');
            $price = preg_replace('/[^\d]/', '', (string) data_get($info, 'context.value.price_string', '')) ?: null;
            $chat->forceFill([
                'client_name' => $client['name'] ?? $chat->client_name,
                'client_id' => $client['id'] ?? $chat->client_id,
                'client_link' => data_get($client, 'public_user_profile.url') ?: $chat->client_link,
                'client_avatar' => $this->clientAvatar($client) ?: $chat->client_avatar,
                'ad_url' => data_get($info, 'context.value.url') ?: $chat->ad_url,
                'ad_id' => data_get($info, 'context.value.id') ?: $chat->ad_id,
                'ad_title' => $title !== '' ? $title : $chat->ad_title,
                'ad_price' => $price ? (int) $price : $chat->ad_price,
                'config_id' => $chat->config_id ?: $this->extractConfigId($title),
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('Avito hydrate chat: '.$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $client
     */
    private function clientAvatar(array $client): ?string
    {
        $default = data_get($client, 'public_user_profile.avatar.default');
        $images = data_get($client, 'public_user_profile.avatar.images');
        if (is_array($images)) {
            foreach (['256x256', '192x192', '128x128', '96x96', '72x72', '64x64', '48x48', '36x36', '24x24'] as $key) {
                if (! empty($images[$key]) && is_string($images[$key])) {
                    return $images[$key];
                }
            }
            foreach ($images as $url) {
                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }
        }

        return is_string($default) && $default !== '' ? $default : null;
    }
}
