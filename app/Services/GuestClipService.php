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

    public function storeUpload(
        User $user,
        UploadedFile $file,
        ?Booking $booking,
        ?Computer $computer,
        int $durationSec = 60
    ): GuestClip {
        if (strtolower((string) $file->getClientOriginalExtension()) !== 'mp4') {
            throw ValidationException::withMessages(['clip' => 'Нужен файл .mp4']);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'clip' => 'Клип больше 48 МБ — укоротите запись.',
            ]);
        }

        $token = Str::lower(Str::random(32));
        $dir = 'clips/'.$user->id;
        $name = $token.'.mp4';
        $path = $file->storeAs($dir, $name, 'public');
        if (! $path) {
            throw ValidationException::withMessages(['clip' => 'Не удалось сохранить клип.']);
        }

        $clip = GuestClip::query()->create([
            'user_id' => $user->id,
            'booking_id' => $booking?->id,
            'computer_id' => $computer?->id ?? $booking?->computer_id,
            'path' => $path,
            'bytes' => (int) $file->getSize(),
            'duration_sec' => max(1, min(180, $durationSec)),
            'share_token' => $token,
        ]);

        $this->pruneUser($user);
        $clip = $clip->fresh();
        if ($clip && $this->telegramConfigured() && config('services.telegram.clips_auto')) {
            $this->postTelegram($clip);
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
            'pc_name' => $clip->computer?->name,
            'telegram_sent' => (bool) $clip->telegram_sent_at,
            'created_at' => optional($clip->created_at)?->timezone(config('app.timezone'))->format('d.m H:i'),
        ];
    }

    public function telegramConfigured(): bool
    {
        return filled(config('services.telegram.bot_token'))
            && filled(config('services.telegram.clips_chat_id'));
    }

    public function postTelegram(GuestClip $clip): bool
    {
        if (! $this->telegramConfigured()) {
            $clip->update(['telegram_error' => 'Бот Telegram не настроен']);

            return false;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($clip->path)) {
            $clip->update(['telegram_error' => 'Файл клипа не найден']);

            return false;
        }

        $full = $disk->path($clip->path);
        $caption = trim('Клип клуба'.($clip->computer?->name ? ' · '.$clip->computer->name : ''));
        $token = (string) config('services.telegram.bot_token');
        $chat = (string) config('services.telegram.clips_chat_id');

        try {
            $response = Http::timeout(60)
                ->attach('video', fopen($full, 'r'), basename($clip->path))
                ->post('https://api.telegram.org/bot'.$token.'/sendVideo', [
                    'chat_id' => $chat,
                    'caption' => $caption,
                    'supports_streaming' => true,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Guest clip Telegram send failed: '.$e->getMessage(), ['clip_id' => $clip->id]);
            $clip->update(['telegram_error' => Str::limit($e->getMessage(), 240)]);

            return false;
        }

        if (! $response->successful() || ! ($response->json('ok'))) {
            $desc = (string) ($response->json('description') ?: $response->body());
            $clip->update(['telegram_error' => Str::limit($desc, 240)]);

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
