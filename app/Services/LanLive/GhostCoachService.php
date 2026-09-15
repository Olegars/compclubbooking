<?php

namespace App\Services\LanLive;

use App\Models\Computer;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class GhostCoachService
{
    public const COOLDOWN_SECONDS = 28;

    public function __construct(
        private readonly ShellGsiStore $gsi,
    ) {
    }

    public function enabled(User $user): bool
    {
        $user->loadMissing('settings');

        return (bool) ($user->settings?->ghost_coach_enabled ?? true);
    }

    public function setEnabled(User $user, bool $on): void
    {
        \App\Models\UserSetting::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['ghost_coach_enabled' => $on]
        );
        $user->unsetRelation('settings');
    }

    /**
     * @param  array<string, mixed>  $snap
     */
    public function maybeWhisper(Computer $computer, User $user, array $snap): ?string
    {
        if (! $this->enabled($user)) {
            return null;
        }
        if (! ($snap['in_match'] ?? false) && ($snap['event'] ?? '') === 'heartbeat') {
            return null;
        }

        $key = 'coach:cd:'.$computer->id;
        if (Cache::has($key)) {
            return null;
        }

        $text = $this->compose($computer, $snap);
        if ($text === null || $text === '') {
            return null;
        }

        Cache::put($key, 1, self::COOLDOWN_SECONDS);

        return $text;
    }

    /**
     * @param  array<string, mixed>  $snap
     */
    public function compose(Computer $computer, array $snap): ?string
    {
        $game = ($snap['game'] ?? '') === 'dota' ? 'dota' : 'cs2';
        $event = strtolower((string) ($snap['event'] ?? ''));
        $clubId = (int) ($computer->club_id ?? 0);
        $others = $clubId > 0 ? $this->gsi->clubStates($clubId, (int) $computer->id) : [];
        $enemies = $this->enemies($snap, $others);

        if ($game === 'cs2') {
            return $this->cs2Line($snap, $event, $enemies);
        }

        return $this->dotaLine($snap, $event, $enemies);
    }

    /**
     * @param  array<string, mixed>  $mine
     * @param  list<array<string, mixed>>  $others
     * @return list<array<string, mixed>>
     */
    private function enemies(array $mine, array $others): array
    {
        $out = [];
        $myMap = strtolower((string) ($mine['map'] ?? ''));
        $myMatch = (string) ($mine['match_id'] ?? '');
        $myTeam = strtolower((string) ($mine['team'] ?? ''));
        $myRound = (int) ($mine['round'] ?? -1);
        foreach ($others as $row) {
            if (! ($row['in_match'] ?? false)) {
                continue;
            }
            if (($row['game'] ?? '') !== ($mine['game'] ?? '')) {
                continue;
            }
            $sameMatch = $myMatch !== '' && $myMatch === (string) ($row['match_id'] ?? '');
            $sameMap = $myMap !== '' && $myMap === strtolower((string) ($row['map'] ?? ''));
            $roundOk = $myRound < 0 || ! isset($row['round']) || abs((int) $row['round'] - $myRound) <= 1;
            if (! $sameMatch && ! ($sameMap && $roundOk)) {
                continue;
            }
            $theirTeam = strtolower((string) ($row['team'] ?? ''));
            $isEnemy = $myTeam !== '' && $theirTeam !== '' && $theirTeam !== $myTeam;
            if (! $isEnemy && ! $sameMatch) {
                continue;
            }
            if ($isEnemy || ($sameMatch && $theirTeam !== $myTeam)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $snap
     * @param  list<array<string, mixed>>  $enemies
     */
    private function cs2Line(array $snap, string $event, array $enemies): ?string
    {
        $money = isset($snap['money']) ? (int) $snap['money'] : null;
        $phase = strtolower((string) ($snap['phase'] ?? ''));

        foreach ($enemies as $e) {
            $em = isset($e['money']) ? (int) $e['money'] : null;
            $pc = (string) ($e['pc_name'] ?? 'соседа');
            if ($em !== null && $em > 0 && $em < 2000) {
                return sprintf('У них эко на %s, %d$. Жди раш с дробовиками.', $pc, $em);
            }
        }

        if ($event === 'bomb' || ($snap['bomb'] ?? '') === 'planted') {
            return 'Бомба заложена. Не выходите по одному — ретейк парой.';
        }

        if ($event === 'death') {
            return 'Вы мертвы. Не тильтуйте бай — посмотрите демо и подскажите тиммейту.';
        }

        if ($phase === 'freezetime' && $money !== null && $money < 2000) {
            return sprintf('У вас эко, %d$. Не форсите — сейв или пистолеты.', $money);
        }

        if ($phase === 'freezetime' && $money !== null && $money >= 5000) {
            foreach ($enemies as $e) {
                $em = isset($e['money']) ? (int) $e['money'] : null;
                if ($em !== null && $em >= 4000) {
                    return 'Оба фуллбай. Ждите стандарт, не дарите первую кровь.';
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $snap
     * @param  list<array<string, mixed>>  $enemies
     */
    private function dotaLine(array $snap, string $event, array $enemies): ?string
    {
        foreach ($enemies as $e) {
            if (! empty($e['ult_ready'])) {
                $hero = $this->heroLabel((string) ($e['hero'] ?? 'герой'));
                $ult = $this->ultLabel((string) ($e['ult_name'] ?? 'ульт'));
                $pc = (string) ($e['pc_name'] ?? 'соседа');

                return sprintf('У вражеского %s на %s готов %s, не кучкуйтесь.', $hero, $pc, $ult);
            }
        }

        if (! empty($snap['ult_ready']) && $event !== 'death') {
            $ult = $this->ultLabel((string) ($snap['ult_name'] ?? 'ульт'));

            return sprintf('Ваш %s готов. Не тратьте в пустоту — ждите файта.', $ult);
        }

        $clock = (int) ($snap['game_time'] ?? $snap['clock'] ?? 0);
        if ($clock >= 1140 && $clock <= 1260) {
            return 'Окно Рошана. Не стойте толпой без варда — смотрите смок и бэкдоры.';
        }
        if ($clock >= 1740 && $clock <= 1860) {
            return 'Второй Рошан скоро. Не отдавайте без байбэка.';
        }

        if ($event === 'death') {
            return 'Смерть. Не бегите в одиночку за телом — дождитесь команды.';
        }

        return null;
    }

    private function heroLabel(string $raw): string
    {
        $n = strtolower($raw);
        $n = preg_replace('/^npc_dota_hero_/', '', $n) ?? $n;
        $map = [
            'enigma' => 'Enigma',
            'tidehunter' => 'Tidehunter',
            'magnataur' => 'Magnus',
            'earthshaker' => 'Earthshaker',
            'faceless_void' => 'Void',
        ];

        return $map[$n] ?? (ucfirst(str_replace('_', ' ', $n)) ?: 'герой');
    }

    private function ultLabel(string $raw): string
    {
        $n = strtolower($raw);
        $map = [
            'enigma_black_hole' => 'Black Hole',
            'tidehunter_ravage' => 'Ravage',
            'magnataur_reverse_polarity' => 'RP',
            'earthshaker_echo_slam' => 'Echo Slam',
            'faceless_void_chronosphere' => 'Chrono',
        ];
        if (isset($map[$n])) {
            return $map[$n];
        }
        if (str_contains($n, 'black_hole')) {
            return 'Black Hole';
        }
        $short = preg_replace('/^[a-z]+_/', '', $n) ?? $n;

        return ucfirst(str_replace('_', ' ', $short));
    }
}
