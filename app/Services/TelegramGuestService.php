<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TelegramGuestService
{
    public function botToken(): string
    {
        return trim((string) config('services.telegram.bot_token'));
    }

    public function botUsername(): string
    {
        return ltrim(trim((string) config('services.telegram.bot_username')), '@');
    }

    public function botConfigured(): bool
    {
        return $this->botToken() !== '';
    }

    public function ensureLinkToken(User $user): string
    {
        $token = strtolower(trim((string) $user->telegram_link_token));
        if (preg_match('/^[a-z0-9]{16,32}$/', $token)) {
            return $token;
        }
        $token = Str::lower(Str::random(24));
        $user->forceFill(['telegram_link_token' => $token])->save();

        return $token;
    }

    /**
     * @return array{linked:bool,username:?string,bot:?string,deep_link:?string}
     */
    public function payload(User $user): array
    {
        $username = $this->botUsername();
        $linked = filled($user->telegram_chat_id);

        return [
            'linked' => $linked,
            'username' => $user->telegram_username,
            'bot' => $username !== '' ? $username : null,
            'deep_link' => (! $linked && $username !== '')
                ? 'https://t.me/'.$username.'?start='.$this->ensureLinkToken($user)
                : null,
        ];
    }

    public function unlink(User $user): void
    {
        $user->forceFill([
            'telegram_chat_id' => null,
            'telegram_username' => null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $update
     */
    public function handleUpdate(array $update): void
    {
        $message = is_array($update['message'] ?? null) ? $update['message'] : [];
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $chatId = (string) ($chat['id'] ?? '');
        $text = trim((string) ($message['text'] ?? ''));
        $fromName = trim((string) ($chat['username'] ?? $message['from']['username'] ?? ''));
        if ($chatId === '' || $text === '') {
            return;
        }

        if (preg_match('/^\/unlink\b/i', $text)) {
            User::query()->where('telegram_chat_id', $chatId)->update([
                'telegram_chat_id' => null,
                'telegram_username' => null,
            ]);
            $this->reply($chatId, 'Telegram отвязан. Клипы в личку больше не придут.');

            return;
        }

        $token = '';
        if (preg_match('/^\/start(?:\s+|_)([a-z0-9]{16,32})$/i', $text, $m)) {
            $token = strtolower($m[1]);
        }
        if ($token === '') {
            $this->reply(
                $chatId,
                'Чтобы получать killcam-клипы, откройте кабинет клуба → «Привязать Telegram».'
            );

            return;
        }

        $user = User::query()->where('telegram_link_token', $token)->first();
        if (! $user) {
            $this->reply($chatId, 'Ссылка устарела. Сгенерируйте новую в кабинете клуба.');

            return;
        }

        User::query()->where('telegram_chat_id', $chatId)
            ->where('id', '!=', $user->id)
            ->update(['telegram_chat_id' => null]);

        $user->forceFill([
            'telegram_chat_id' => $chatId,
            'telegram_username' => $fromName !== '' ? mb_substr($fromName, 0, 64) : $user->telegram_username,
        ])->save();

        $this->reply($chatId, 'Готово. Killcam-клипы с ПК клуба будут приходить сюда.');
    }

    public function reply(string $chatId, string $text): void
    {
        $token = $this->botToken();
        if ($token === '') {
            return;
        }
        try {
            Http::timeout(8)->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                'chat_id' => $chatId,
                'text' => $text,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Telegram guest reply failed: '.$e->getMessage());
        }
    }
}
