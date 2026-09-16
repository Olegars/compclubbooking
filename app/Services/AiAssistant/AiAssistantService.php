<?php

namespace App\Services\AiAssistant;

use App\Models\AiAssistantSetting;
use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\Game;
use App\Models\User;
use App\Services\LanLive\ShellGsiStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AiAssistantService
{
    public function __construct(
        private readonly SpeechService $speech,
        private readonly DeepSeekChat $llm,
    ) {
    }

    public function isConfigured(?int $clubId = null): bool
    {
        return AiAssistantSetting::isReady($clubId);
    }

    /**
     * @return array{
     *   transcript:string,
     *   reply_text:string,
     *   audio_mime:string,
     *   audio_base64:string,
     *   booking_id:int,
     *   game_id:?int,
     *   game_title:?string
     * }
     */
    public function handle(
        int $terminalId,
        UploadedFile $audio,
        ?int $gameId = null,
        ?string $gameTitle = null,
        ?string $ttsVoice = null,
    ): array {
        $computer = Computer::query()->find($terminalId);
        if (! $computer) {
            throw new RuntimeException('Терминал не найден.');
        }

        $clubId = $computer->club_id ? (int) $computer->club_id : null;
        if ($reason = AiAssistantSetting::denyReason($clubId)) {
            throw new RuntimeException($reason);
        }

        $booking = $this->activeBookingForTerminal($terminalId);
        if (! $booking) {
            throw new RuntimeException('Нужна активная сессия на этом ПК.');
        }

        $user = $booking->user_id ? User::query()->find($booking->user_id) : null;
        $club = Club::query()->find($computer->club_id);
        $settings = AiAssistantSetting::forClub($clubId);

        $resolvedGame = $this->resolveGame($gameId, $gameTitle);

        $transcript = $this->speech->transcribe($audio, $settings);
        $gsi = app(ShellGsiStore::class)->get($terminalId);
        if (is_array($gsi) && ($gsi['in_match'] ?? false)) {
            $bits = array_filter([
                'игра '.($gsi['game'] ?? ''),
                ! empty($gsi['map']) ? 'карта '.$gsi['map'] : null,
                ! empty($gsi['phase']) ? 'фаза '.$gsi['phase'] : null,
                isset($gsi['money']) ? 'деньги '.$gsi['money'] : null,
                ! empty($gsi['hero']) ? 'герой '.$gsi['hero'] : null,
                ! empty($gsi['ult_name']) ? 'ульт '.$gsi['ult_name'].((! empty($gsi['ult_ready'])) ? ' готов' : '') : null,
                ! empty($gsi['bomb']) ? 'бомба '.$gsi['bomb'] : null,
            ]);
            if ($bits !== []) {
                $transcript .= "\n[GSI] ".implode(', ', $bits);
            }
        }
        $reply = $this->llm->reply($transcript, [
            'game_title' => $resolvedGame['title'],
            'player_name' => $user?->name,
            'club_name' => $club?->name,
            'club_id' => $clubId,
        ]);
        $speech = $this->speech->synthesize(
            $reply,
            $settings,
            $settings->resolveVoiceForPlayer($user, $ttsVoice)
        );

        Log::info('[AI-ASSISTANT]', [
            'terminal_id' => $terminalId,
            'booking_id' => $booking->id,
            'club_id' => $computer->club_id,
            'game_id' => $resolvedGame['id'],
            'game_title' => $resolvedGame['title'],
            'transcript_len' => mb_strlen($transcript),
            'reply_len' => mb_strlen($reply),
        ]);

        return [
            'transcript' => $transcript,
            'reply_text' => $reply,
            'audio_mime' => $speech['mime'],
            'audio_base64' => base64_encode($speech['binary']),
            'booking_id' => (int) $booking->id,
            'game_id' => $resolvedGame['id'],
            'game_title' => $resolvedGame['title'],
        ];
    }

    private function activeBookingForTerminal(int $terminalId): ?Booking
    {
        $termId = (string) $terminalId;

        return Booking::query()
            ->where('status', 'active')
            ->where(function ($query) use ($termId, $terminalId) {
                $query->where('computer_id', $terminalId);
                if (DB::connection()->getDriverName() === 'pgsql') {
                    $query->orWhereJsonContains('pc_ids', $termId);
                } else {
                    $query->orWhere('pc_ids', 'like', '%"'.$termId.'"%');
                }
            })
            ->latest('id')
            ->first();
    }

    /**
     * @return array{id:?int,title:?string}
     */
    private function resolveGame(?int $gameId, ?string $gameTitle): array
    {
        if ($gameId) {
            $game = Game::query()->find($gameId);
            if ($game) {
                return ['id' => (int) $game->id, 'title' => (string) $game->title];
            }
        }

        $title = $gameTitle !== null ? trim($gameTitle) : '';
        if ($title !== '') {
            return ['id' => $gameId, 'title' => $title];
        }

        return ['id' => null, 'title' => null];
    }
}
