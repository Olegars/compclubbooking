<?php

namespace App\Services;

use App\Models\GameRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class GameRequestService
{
    public function create(User $user, string $title, ?string $comment, string $source): GameRequest
    {
        $title = trim($title);
        if ($title === '') {
            throw ValidationException::withMessages([
                'title' => 'Укажите название игры',
            ]);
        }

        $normalized = GameRequest::normalizeTitle($title);

        $recent = GameRequest::query()
            ->where('user_id', $user->id)
            ->where('title_normalized', $normalized)
            ->where('created_at', '>=', now()->subDays(7))
            ->exists();

        if ($recent) {
            throw ValidationException::withMessages([
                'title' => 'Вы уже запрашивали эту игру за последние 7 дней',
            ]);
        }

        return GameRequest::create([
            'user_id' => $user->id,
            'title' => mb_substr($title, 0, 120),
            'title_normalized' => $normalized,
            'comment' => $comment ? mb_substr(trim($comment), 0, 500) : null,
            'source' => in_array($source, [GameRequest::SOURCE_CABINET, GameRequest::SOURCE_SHELL], true)
                ? $source
                : GameRequest::SOURCE_CABINET,
            'status' => GameRequest::STATUS_OPEN,
        ]);
    }
}
