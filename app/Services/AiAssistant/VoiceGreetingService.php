<?php

namespace App\Services\AiAssistant;

use App\Models\AiAssistantSetting;
use App\Models\Booking;
use App\Models\Club;
use App\Models\Computer;
use App\Models\Game;
use App\Models\User;
use App\Models\UserGameStat;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class VoiceGreetingService
{
    public function __construct(
        private readonly DeepSeekChat $llm,
        private readonly SpeechService $speech,
    ) {
    }

    public function isConfigured(?int $clubId = null): bool
    {
        if (! config('ai_assistant.enabled')) {
            return false;
        }

        $settings = AiAssistantSetting::forClub($clubId);

        return $settings->is_enabled && $settings->hasCredentials();
    }

    /**
     * @return array{
     *   reply_text:string,
     *   audio_mime:string,
     *   audio_base64:string,
     *   booking_id:int,
     *   is_first_visit:bool,
     *   visit_count_completed:int,
     *   favorite_games:array<int, array{id:int,title:string,launch_count:int}>,
     *   context:array{player_name:?string,club_name:?string,pc_name:?string,time_remaining:?string}
     * }
     */
    public function greet(int $terminalId, ?int $bookingId = null): array
    {
        $computer = Computer::query()->find($terminalId);
        if (! $computer) {
            throw new RuntimeException('Терминал не найден.');
        }

        $clubId = $computer->club_id ? (int) $computer->club_id : null;
        if (! $this->isConfigured($clubId)) {
            throw new RuntimeException('AI-ассистент выключен или не настроен (ключи / AI_ASSISTANT_ENABLED).');
        }

        $booking = $this->activeBookingForTerminal($terminalId);
        if (! $booking) {
            throw new RuntimeException('Нужна активная сессия на этом ПК.');
        }

        if ($bookingId && (int) $booking->id !== $bookingId) {
            throw new RuntimeException('Бронь не совпадает с активной сессией на этом ПК.');
        }

        $user = $booking->user_id ? User::query()->find($booking->user_id) : null;
        if (! $user) {
            throw new RuntimeException('Пользователь сессии не найден.');
        }

        $club = Club::query()->find($computer->club_id);
        $settings = AiAssistantSetting::forClub($clubId);
        $favoriteGames = $this->favoriteGamesForUser((int) $user->id);
        $visitCountCompleted = $this->completedVisitCount((int) $user->id);
        $isFirstVisit = $this->isFirstVisit((int) $user->id, (int) $booking->id);
        $timeRemaining = $this->formatTimeRemaining($booking);

        $context = [
            'player_name' => $user->name,
            'club_name' => $club?->name,
            'pc_name' => $computer->name,
            'time_remaining' => $timeRemaining,
            'is_first_visit' => $isFirstVisit,
            'visit_count_completed' => $visitCountCompleted,
            'favorite_games' => $favoriteGames,
            'club_id' => $clubId,
        ];

        $reply = $this->llm->greet($context);
        $speech = $this->speech->synthesize($reply, $settings);

        Log::info('[AI-GREETING]', [
            'terminal_id' => $terminalId,
            'booking_id' => $booking->id,
            'user_id' => $user->id,
            'is_first_visit' => $isFirstVisit,
            'visit_count_completed' => $visitCountCompleted,
            'favorite_count' => count($favoriteGames),
            'reply_len' => mb_strlen($reply),
        ]);

        return [
            'reply_text' => $reply,
            'audio_mime' => $speech['mime'],
            'audio_base64' => base64_encode($speech['binary']),
            'booking_id' => (int) $booking->id,
            'is_first_visit' => $isFirstVisit,
            'visit_count_completed' => $visitCountCompleted,
            'favorite_games' => $favoriteGames,
            'context' => [
                'player_name' => $user->name,
                'club_name' => $club?->name,
                'pc_name' => $computer->name,
                'time_remaining' => $timeRemaining,
            ],
        ];
    }

    private function activeBookingForTerminal(int $terminalId): ?Booking
    {
        $termId = (string) $terminalId;

        return Booking::query()
            ->where('status', 'active')
            ->where(function ($query) use ($termId, $terminalId) {
                $query->where('computer_id', $terminalId)
                    ->orWhereJsonContains('pc_ids', $termId);
            })
            ->latest('id')
            ->first();
    }

    /**
     * @return array<int, array{id:int,title:string,launch_count:int}>
     */
    private function favoriteGamesForUser(int $userId): array
    {
        $stats = UserGameStat::query()
            ->with('game:id,title')
            ->where('user_id', $userId)
            ->orderByDesc('launch_count')
            ->orderByDesc('last_launched_at')
            ->limit(5)
            ->get();

        $out = [];
        foreach ($stats as $stat) {
            $title = $stat->game?->title;
            if (! $title) {
                continue;
            }
            $out[] = [
                'id' => (int) $stat->game_id,
                'title' => (string) $title,
                'launch_count' => (int) $stat->launch_count,
            ];
        }

        return $out;
    }

    private function completedVisitCount(int $userId): int
    {
        return (int) Booking::query()
            ->where('user_id', $userId)
            ->where('status', 'completed')
            ->count();
    }

    private function isFirstVisit(int $userId, int $currentBookingId): bool
    {
        $priorStarted = Booking::query()
            ->where('user_id', $userId)
            ->where('id', '!=', $currentBookingId)
            ->whereNotNull('actual_started_at')
            ->exists();

        if ($priorStarted) {
            return false;
        }

        return $this->completedVisitCount($userId) === 0;
    }

    private function formatTimeRemaining(Booking $booking): ?string
    {
        if (! $booking->ends_at) {
            return null;
        }

        $seconds = (int) floor(now()->diffInSeconds($booking->ends_at, false));
        $seconds = max(0, $seconds);

        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }
}
