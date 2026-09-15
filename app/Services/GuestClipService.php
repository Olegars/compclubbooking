<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Computer;
use App\Models\GuestClip;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GuestClipService
{
    public const MAX_BYTES = 48 * 1024 * 1024;

    public const MAX_PER_USER = 20;

    /**
     * @param  array{share_token?:string,aspect?:string,source?:string}  $meta
     */
    public function storeUpload(
        User $user,
        UploadedFile $file,
        ?Booking $booking,
        ?Computer $computer,
        int $durationSec = 60,
        array $meta = [],
    ): GuestClip {
        if (strtolower((string) $file->getClientOriginalExtension()) !== 'mp4') {
            throw ValidationException::withMessages(['clip' => 'Нужен файл .mp4']);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'clip' => 'Клип больше 48 МБ — укоротите запись.',
            ]);
        }

        $token = $this->resolveShareToken((string) ($meta['share_token'] ?? ''));
        $dir = 'clips/'.$user->id;
        $name = $token.'.mp4';
        $path = $file->storeAs($dir, $name, 'public');
        if (! $path) {
            throw ValidationException::withMessages(['clip' => 'Не удалось сохранить клип.']);
        }

        $aspect = strtolower(trim((string) ($meta['aspect'] ?? '')));
        if (! in_array($aspect, ['9:16', '16:9', '1:1'], true)) {
            $aspect = null;
        }
        $source = strtolower(trim((string) ($meta['source'] ?? 'manual')));
        if (! in_array($source, ['manual', 'kill', 'logout'], true)) {
            $source = 'manual';
        }

        $clip = GuestClip::query()->create([
            'user_id' => $user->id,
            'booking_id' => $booking?->id,
            'computer_id' => $computer?->id ?? $booking?->computer_id,
            'path' => $path,
            'bytes' => (int) $file->getSize(),
            'duration_sec' => max(1, min(180, $durationSec)),
            'aspect' => $aspect,
            'source' => $source,
            'share_token' => $token,
        ]);

        $this->pruneUser($user);
        $clip = $clip->fresh();
        if ($clip && $this->botConfigured()) {
            $this->postTelegram($clip, (bool) config('services.telegram.clips_auto'));
        }

        return $clip->fresh();
    }

    public function serialize(GuestClip $clip): array
    {
        return [
            'id' => $clip->id,
            'url' => $clip->publicUrl(),
            'share_url' => $clip->shareUrl(),
            'bytes' => $clip->bytes,
            'duration_sec' => $clip->duration_sec,
            'aspect' => $clip->aspect,
            'source' => $clip->source,
            'pc_name' => $clip->computer?->name,
            'telegram_sent' => (bool) $clip->telegram_sent_at,
            'created_at' => optional($clip->created_at)?->timezone(config('app.timezone'))->format('d.m H:i'),
        ];
    }

    public function botConfigured(): bool
    {
        return filled(config('services.telegram.bot_token'));
    }

    public function telegramConfigured(): bool
    {
        return $this->botConfigured() && filled(config('services.telegram.clips_chat_id'));
    }

    public function postTelegram(GuestClip $clip, bool $includeChannel = true): bool
    {
        if (! $this->botConfigured()) {
            $clip->update(['telegram_error' => 'Бот Telegram не настроен']);

            return false;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($clip->path)) {
            $clip->update(['telegram_error' => 'Файл клипа не найден']);

            return false;
        }

        $full = $disk->path($clip->path);
        $clip->loadMissing(['user:id,name,telegram_chat_id', 'computer:id,name']);
        $nick = trim((string) ($clip->user?->name ?? ''));
        $pc = trim((string) ($clip->computer?->name ?? ''));
        $caption = trim(implode("\n", array_filter([
            ($nick !== '' ? $nick : 'Клип клуба').($pc !== '' ? ' · '.$pc : ''),
            $clip->aspect === '9:16' ? 'Reels / Shorts 9:16' : null,
            $clip->shareUrl(),
            url('/'),
        ])));
        $token = (string) config('services.telegram.bot_token');
        $chats = [];
        $guestChat = trim((string) ($clip->user?->telegram_chat_id ?? ''));
        if ($guestChat !== '') {
            $chats[] = $guestChat;
        }
        if ($includeChannel) {
            $chats[] = (string) config('services.telegram.clips_chat_id');
            $chats[] = (string) config('services.telegram.clips_guest_chat_id');
        }
        $chats = array_values(array_unique(array_filter($chats)));
        if ($chats === []) {
            $clip->update(['telegram_error' => 'Нет Telegram: привяжите бота в кабинете или задайте канал клуба']);

            return false;
        }

        $okAny = false;
        $lastError = null;
        foreach ($chats as $chat) {
            $fields = [
                'chat_id' => $chat,
                'caption' => $caption,
                'supports_streaming' => true,
            ];
            if ($clip->aspect === '9:16') {
                $fields['width'] = 1080;
                $fields['height'] = 1920;
            }
            try {
                $response = Http::timeout(60)
                    ->attach('video', fopen($full, 'r'), basename($clip->path))
                    ->post('https://api.telegram.org/bot'.$token.'/sendVideo', $fields);
            } catch (\Throwable $e) {
                Log::warning('Guest clip Telegram send failed: '.$e->getMessage(), ['clip_id' => $clip->id]);
                $lastError = Str::limit($e->getMessage(), 240);

                continue;
            }

            if (! $response->successful() || ! ($response->json('ok'))) {
                $desc = (string) ($response->json('description') ?: $response->body());
                $lastError = Str::limit($desc, 240);

                continue;
            }
            $okAny = true;
        }

        if (! $okAny) {
            $clip->update(['telegram_error' => $lastError ?: 'Telegram не принял клип']);

            return false;
        }

        $clip->update([
            'telegram_sent_at' => now(),
            'telegram_error' => null,
        ]);

        return true;
    }

    public function destroy(GuestClip $clip): void
    {
        Storage::disk('public')->delete($clip->path);
        $clip->delete();
    }

    private function resolveShareToken(string $wanted): string
    {
        $wanted = strtolower(trim($wanted));
        if (preg_match('/^[a-z0-9]{24,48}$/', $wanted)
            && ! GuestClip::query()->where('share_token', $wanted)->exists()) {
            return $wanted;
        }

        return Str::lower(Str::random(32));
    }

    private function pruneUser(User $user): void
    {
        $extra = GuestClip::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->skip(self::MAX_PER_USER)
            ->take(50)
            ->get();
        foreach ($extra as $clip) {
            $this->destroy($clip);
        }
    }
}
