<?php

namespace App\Services;

use App\Models\User;
use App\Services\AiAssistant\DeepSeekChat;
use Illuminate\Support\Facades\Log;

class PlayerNicknameService
{
    /** Запас, если DeepSeek недоступен: произносимые клички без _ и -. */
    private const FALLBACK = [
        'Nova', 'Volt', 'Ember', 'Drift', 'Pulse', 'Frost', 'Blaze', 'Echo',
        'Storm', 'Pixel', 'Raven', 'Hawk', 'Luna', 'Vega', 'Orion', 'Apex',
        'Flint', 'Ghost', 'Sage', 'Talon', 'Rift', 'Mira', 'Nyx', 'Onyx',
        'Pike', 'Wave', 'Zen', 'Kai', 'Rex', 'Iris', 'Jade', 'Dash',
        'Fox', 'Nox', 'Umbra', 'Quill', 'Lumen', 'Yara', 'Vex', 'Aero',
    ];

    private const BANNED = [
        'gamer', 'player', 'user', 'stalker', 'admin', 'guest', 'test',
        'qwerty', 'asdf', 'nick', 'nickname', 'null', 'none',
    ];

    public function __construct(private readonly DeepSeekChat $llm) {}

    public function assignForNewUser(): string
    {
        try {
            $raw = $this->llm->complete(
                $this->systemPrompt(),
                'Придумай один новый ник прямо сейчас.',
                temperature: 1.0,
                maxTokens: 48,
                timeout: 8.0,
            );
            $nick = $this->sanitize($raw);
            if ($nick !== null && ! $this->isTaken($nick)) {
                return $nick;
            }
        } catch (\Throwable $e) {
            Log::warning('PlayerNicknameService: LLM nick failed: '.$e->getMessage());
        }

        return $this->fallbackNick();
    }

    public function sanitize(string $raw): ?string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $raw);
        $line = trim(explode("\n", $normalized, 2)[0]);
        $line = trim($line, " \t\"'`«»“”.,;!");
        if (str_contains($line, ':')) {
            $line = trim((string) substr($line, (int) strrpos($line, ':') + 1));
        }

        $nick = preg_replace('/[^A-Za-zА-Яа-яЁё]/u', '', $line) ?? '';
        $len = mb_strlen($nick);
        if ($len < 4 || $len > 16) {
            return null;
        }

        if (in_array(mb_strtolower($nick, 'UTF-8'), self::BANNED, true)) {
            return null;
        }

        if (mb_strtoupper($nick, 'UTF-8') === $nick || mb_strtolower($nick, 'UTF-8') === $nick) {
            $nick = mb_convert_case($nick, MB_CASE_TITLE, 'UTF-8');
        }

        return $nick;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Ты придумываешь игровой ник для нового гостя компьютерного клуба.

Ответь ровно одним ником — без кавычек, точек, пояснений и списка.

Правила ника:
- легко произнести вслух (один-два слога или два коротких слова слитно, CamelCase)
- только буквы, латиница или кириллица
- без пробелов, цифр, подчёркиваний _, дефисов - и прочих знаков
- 4–12 символов
- не банальности Gamer, Player, User, Stalker, Admin, Guest
- без оскорблений и 18+
PROMPT;
    }

    private function isTaken(string $nick): bool
    {
        return User::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($nick, 'UTF-8')])
            ->exists();
    }

    private function fallbackNick(): string
    {
        $pool = self::FALLBACK;
        shuffle($pool);
        foreach ($pool as $candidate) {
            if (! $this->isTaken($candidate)) {
                return $candidate;
            }
        }

        for ($i = 0; $i < 40; $i++) {
            $candidate = $pool[array_rand($pool)].random_int(2, 99);
            if (! $this->isTaken($candidate)) {
                return $candidate;
            }
        }

        return 'Nova'.random_int(100, 999);
    }
}
