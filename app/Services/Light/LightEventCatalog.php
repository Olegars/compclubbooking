<?php

namespace App\Services\Light;

use App\Models\ClubLightSetting;
use App\Models\SpaceLight;

/**
 * Club-wide DMX event presets (lifecycle + games). Shell plays overlays;
 * cloud uses fade / idle / off colors from the same catalog.
 */
class LightEventCatalog
{
    public const COLOR_AUTO = 'auto';

    public const EFFECT_NONE = 'none';

    public const EFFECT_RAINBOW = 'rainbow';

    public const EFFECT_CYCLE = 'cycle';

    /** Named colors for overlays (room picker stays on SpaceLight::COLORS). */
    public const EVENT_COLORS = ['white', 'red', 'blue', 'green', 'yellow', 'purple', 'orange', 'cold_white'];

    /**
     * @return list<array{id:string,title:string,hint:string,group:string,allow_auto:bool}>
     */
    public static function definitions(): array
    {
        return [
            [
                'id' => 'pc_on',
                'title' => 'Включение компьютера',
                'hint' => 'ПК в комнате запитан, сессии ещё нет — лобби.',
                'group' => 'Сессия и питание',
                'allow_auto' => false,
            ],
            [
                'id' => 'session_start',
                'title' => 'Открытие сессии',
                'hint' => 'PIN/QR логин. После отработки — цвет игрока (первый визит берёт цвет события).',
                'group' => 'Сессия и питание',
                'allow_auto' => false,
            ],
            [
                'id' => 'session_end',
                'title' => 'Конец сессии',
                'hint' => 'Логаут при живых ПК. После отработки — сцена «включение компьютера».',
                'group' => 'Сессия и питание',
                'allow_auto' => false,
            ],
            [
                'id' => 'pc_shutdown',
                'title' => 'Выключение компьютера',
                'hint' => 'Последний ПК комнаты гаснет — анимация перед «выключен».',
                'group' => 'Сессия и питание',
                'allow_auto' => false,
            ],
            [
                'id' => 'pc_off',
                'title' => 'Компьютер выключен',
                'hint' => 'Все ПК комнаты off. Держится, пока кто-то не включится.',
                'group' => 'Сессия и питание',
                'allow_auto' => false,
            ],
            [
                'id' => 'cs2.bomb',
                'title' => 'CS2: бомба установлена',
                'hint' => 'Пока planted. Длительность 0 = до диффуза / взрыва / конца раунда.',
                'group' => 'CS2',
                'allow_auto' => false,
            ],
            [
                'id' => 'cs2.win',
                'title' => 'CS2: победа раунда',
                'hint' => 'phase=over + win_team.',
                'group' => 'CS2',
                'allow_auto' => false,
            ],
            [
                'id' => 'cs2.death',
                'title' => 'CS2: смерть',
                'hint' => 'Пока игрок мёртв в раунде.',
                'group' => 'CS2',
                'allow_auto' => false,
            ],
            [
                'id' => 'cs2.ambient.winter',
                'title' => 'CS2: зима (Nuke / Ancient снаружи)',
                'hint' => 'Холодный белый, пока на Nuke или снаружи Ancient. Длительность 0 = держать.',
                'group' => 'CS2',
                'allow_auto' => false,
            ],
            [
                'id' => 'cs2.ambient.inferno',
                'title' => 'CS2: инферно / огонь',
                'hint' => 'Мягкий оранжевый на Inferno или при горении (molotov). Длительность 0 = держать.',
                'group' => 'CS2',
                'allow_auto' => false,
            ],
            [
                'id' => 'cs2.flash',
                'title' => 'CS2: светошумовая (blind)',
                'hint' => 'GSI player.state.flashed. Дефолт: 100% белый 0.8 с.',
                'group' => 'CS2',
                'allow_auto' => false,
            ],
            [
                'id' => 'dota.win',
                'title' => 'Dota 2: победа',
                'hint' => 'win_team на карте.',
                'group' => 'Dota 2',
                'allow_auto' => false,
            ],
            [
                'id' => 'dota.death',
                'title' => 'Dota 2: смерть героя',
                'hint' => 'Пока герой мёртв.',
                'group' => 'Dota 2',
                'allow_auto' => false,
            ],
            [
                'id' => 'gamesense.bomb',
                'title' => 'GameSense: бомба',
                'hint' => 'Событие bomb / explode из игры.',
                'group' => 'SteelSeries GameSense',
                'allow_auto' => false,
            ],
            [
                'id' => 'gamesense.win',
                'title' => 'GameSense: победа',
                'hint' => 'win / victory / round_over.',
                'group' => 'SteelSeries GameSense',
                'allow_auto' => false,
            ],
            [
                'id' => 'gamesense.death',
                'title' => 'GameSense: смерть',
                'hint' => 'death / health=0.',
                'group' => 'SteelSeries GameSense',
                'allow_auto' => false,
            ],
            [
                'id' => 'gamesense.hit',
                'title' => 'GameSense: удар',
                'hint' => 'kill / hit / damage.',
                'group' => 'SteelSeries GameSense',
                'allow_auto' => false,
            ],
            [
                'id' => 'gamesense',
                'title' => 'GameSense: цвет кадра',
                'hint' => 'Ambient RGB из игры. «Авто» = цвет из пакета; иначе фиксированный.',
                'group' => 'SteelSeries GameSense',
                'allow_auto' => true,
            ],
            [
                'id' => 'chroma',
                'title' => 'Razer Chroma',
                'hint' => 'REST 127.0.0.1:54235. «Авто» = цвет приборов из игры.',
                'group' => 'Razer Chroma',
                'allow_auto' => true,
            ],
            [
                'id' => 'arena.win',
                'title' => 'Арена: победа в дуэли',
                'hint' => 'Закрытие котла Skill Pot. Золотой строб ~3 с на месте победителя.',
                'group' => 'Арена',
                'allow_auto' => false,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function defaults(): array
    {
        $fadeIdle = max(0, (int) config('light.fade_idle_ms', 1200)) / 1000;
        $fadeLogin = max(0, (int) config('light.fade_login_ms', 2500)) / 1000;
        $fadeOff = max(0, (int) config('light.fade_off_ms', 800)) / 1000;
        $br = SpaceLight::normalizeBrightness((int) config('light.default_brightness', 80));

        $base = [
            'enabled' => true,
            'duration_sec' => 0,
            'color' => 'white',
            'effect' => self::EFFECT_NONE,
            'brightness' => 100,
            'strobe' => false,
            'strobe_on_ms' => 90,
            'strobe_off_ms' => 90,
            'cycle_colors' => [],
            'cycle_hold_sec' => 0.4,
            'fade_sec' => 0.3,
        ];

        $events = [];
        foreach (self::definitions() as $def) {
            $events[$def['id']] = $base;
        }

        $events['pc_on'] = array_merge($base, [
            'color' => 'white',
            'brightness' => $br,
            'fade_sec' => $fadeIdle,
        ]);
        $events['session_start'] = array_merge($base, [
            'color' => 'green',
            'brightness' => $br,
            'fade_sec' => $fadeLogin,
        ]);
        $events['session_end'] = array_merge($base, [
            'color' => 'white',
            'brightness' => $br,
            'fade_sec' => $fadeIdle,
        ]);
        $events['pc_shutdown'] = array_merge($base, [
            'color' => 'white',
            'brightness' => $br,
            'duration_sec' => $fadeOff,
            'fade_sec' => $fadeOff,
        ]);
        $events['pc_off'] = array_merge($base, [
            'color' => 'white',
            'brightness' => 0,
            'fade_sec' => $fadeOff,
        ]);
        $events['cs2.bomb'] = array_merge($base, [
            'color' => 'red',
            'strobe' => true,
            'strobe_on_ms' => 90,
            'strobe_off_ms' => 90,
            'fade_sec' => 0,
        ]);
        $events['cs2.win'] = array_merge($base, [
            'color' => 'blue',
            'duration_sec' => 2.5,
            'fade_sec' => 0.3,
        ]);
        $events['cs2.death'] = array_merge($base, [
            'color' => 'white',
            'brightness' => 12,
            'fade_sec' => 0.2,
        ]);
        $events['cs2.ambient.winter'] = array_merge($base, [
            'color' => 'cold_white',
            'brightness' => 85,
            'fade_sec' => 0.8,
        ]);
        $events['cs2.ambient.inferno'] = array_merge($base, [
            'color' => 'orange',
            'brightness' => 80,
            'fade_sec' => 0.5,
        ]);
        $events['cs2.flash'] = array_merge($base, [
            'color' => 'white',
            'brightness' => 100,
            'duration_sec' => 0.8,
            'fade_sec' => 0,
        ]);
        $events['dota.win'] = array_merge($base, [
            'color' => 'blue',
            'duration_sec' => 2.5,
            'fade_sec' => 0.3,
        ]);
        $events['dota.death'] = array_merge($base, [
            'color' => 'white',
            'brightness' => 12,
            'fade_sec' => 0.2,
        ]);
        $events['gamesense.bomb'] = $events['cs2.bomb'];
        $events['gamesense.win'] = $events['cs2.win'];
        $events['gamesense.death'] = $events['cs2.death'];
        $events['gamesense.hit'] = array_merge($base, [
            'color' => 'red',
            'brightness' => 80,
            'duration_sec' => 0.4,
            'fade_sec' => 0,
        ]);
        $events['gamesense'] = array_merge($base, [
            'color' => self::COLOR_AUTO,
            'fade_sec' => 0.2,
        ]);
        $events['chroma'] = array_merge($base, [
            'color' => self::COLOR_AUTO,
            'fade_sec' => 0.2,
        ]);
        $events['arena.win'] = array_merge($base, [
            'color' => 'yellow',
            'brightness' => 100,
            'strobe' => true,
            'strobe_on_ms' => 80,
            'strobe_off_ms' => 80,
            'duration_sec' => 3,
            'fade_sec' => 0,
        ]);

        return $events;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function forClub(?int $clubId): array
    {
        $stored = [];
        if ($clubId) {
            $row = ClubLightSetting::query()->where('club_id', $clubId)->first();
            $stored = is_array($row?->events) ? $row->events : [];
        }

        return $this->mergeStored($stored);
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, array<string, mixed>>
     */
    public function mergeStored(array $stored): array
    {
        $out = [];
        foreach (self::defaults() as $id => $default) {
            $raw = $stored[$id] ?? [];
            $out[$id] = $this->normalizeEvent($id, is_array($raw) ? $raw : [], $default);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, array<string, mixed>>
     */
    public function sanitizeIncoming(array $incoming): array
    {
        return $this->mergeStored($incoming);
    }

    /**
     * @return array<string, mixed>
     */
    public function event(?int $clubId, string $id): array
    {
        $all = $this->forClub($clubId);

        return $all[$id] ?? self::defaults()[$id] ?? $this->normalizeEvent($id, [], [
            'enabled' => true,
            'duration_sec' => 0,
            'color' => 'white',
            'effect' => self::EFFECT_NONE,
            'brightness' => 80,
            'strobe' => false,
            'strobe_on_ms' => 90,
            'strobe_off_ms' => 90,
            'cycle_colors' => [],
            'cycle_hold_sec' => 0.4,
            'fade_sec' => 0.3,
        ]);
    }

    public function fadeMs(?int $clubId, string $id): int
    {
        $event = $this->event($clubId, $id);

        return (int) round(max(0, (float) $event['fade_sec']) * 1000);
    }

    public function durationMs(?int $clubId, string $id): int
    {
        $event = $this->event($clubId, $id);

        return (int) round(max(0, (float) $event['duration_sec']) * 1000);
    }

    /**
     * Overlay (strobe / cycle / timed color) — shell should play play_event.
     *
     * @param  array<string, mixed>  $event
     */
    public static function wantsOverlay(array $event): bool
    {
        if (! ($event['enabled'] ?? true)) {
            return false;
        }
        if (! empty($event['strobe'])) {
            return true;
        }
        if (($event['effect'] ?? self::EFFECT_NONE) === self::EFFECT_CYCLE
            && count((array) ($event['cycle_colors'] ?? [])) >= 2) {
            return true;
        }
        if (($event['effect'] ?? self::EFFECT_NONE) === self::EFFECT_RAINBOW
            && (float) ($event['duration_sec'] ?? 0) > 0) {
            return true;
        }

        return (float) ($event['duration_sec'] ?? 0) > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function adminPayload(?int $clubId): array
    {
        $events = $this->forClub($clubId);
        $out = [];
        foreach (self::definitions() as $def) {
            $out[] = array_merge($def, [
                'settings' => $events[$def['id']],
            ]);
        }

        return $out;
    }

    /**
     * Compact map for the shell (no titles).
     *
     * @return array<string, array<string, mixed>>
     */
    public function shellPayload(?int $clubId): array
    {
        return $this->forClub($clubId);
    }

    public function sourceForClub(?int $clubId): string
    {
        if (! $clubId) {
            return 'presets';
        }

        return ClubLightSetting::query()->where('club_id', $clubId)->exists()
            ? 'admin'
            : 'presets';
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    private function normalizeEvent(string $id, array $raw, array $default): array
    {
        $allowAuto = false;
        foreach (self::definitions() as $def) {
            if ($def['id'] === $id) {
                $allowAuto = (bool) $def['allow_auto'];
                break;
            }
        }

        $effect = strtolower(trim((string) ($raw['effect'] ?? $default['effect'])));
        if (! in_array($effect, [self::EFFECT_NONE, self::EFFECT_RAINBOW, self::EFFECT_CYCLE], true)) {
            $effect = self::EFFECT_NONE;
        }

        $color = $this->normalizeColor((string) ($raw['color'] ?? $default['color']), $allowAuto, $effect);
        if ($effect === self::EFFECT_RAINBOW && $color !== self::COLOR_AUTO) {
            $color = self::EFFECT_RAINBOW;
        }

        $cycle = [];
        foreach ((array) ($raw['cycle_colors'] ?? $default['cycle_colors']) as $c) {
            $n = $this->normalizeColor((string) $c, false, self::EFFECT_NONE);
            if ($n !== self::COLOR_AUTO && $n !== self::EFFECT_RAINBOW && ! in_array($n, $cycle, true)) {
                $cycle[] = $n;
            }
            if (count($cycle) >= 8) {
                break;
            }
        }
        if ($effect === self::EFFECT_CYCLE && count($cycle) < 2) {
            $cycle = ['red', 'blue'];
        }

        $hold = (float) ($raw['cycle_hold_sec'] ?? $default['cycle_hold_sec']);
        $hold = max(0.05, min(30, $hold));

        return [
            'enabled' => array_key_exists('enabled', $raw)
                ? (bool) $raw['enabled']
                : (bool) $default['enabled'],
            'duration_sec' => round(max(0, min(120, (float) ($raw['duration_sec'] ?? $default['duration_sec']))), 2),
            'color' => $color,
            'effect' => $effect,
            'brightness' => SpaceLight::normalizeBrightness((int) ($raw['brightness'] ?? $default['brightness'])),
            'strobe' => (bool) ($raw['strobe'] ?? $default['strobe']),
            'strobe_on_ms' => max(0, min(5000, (int) ($raw['strobe_on_ms'] ?? $default['strobe_on_ms']))),
            'strobe_off_ms' => max(0, min(5000, (int) ($raw['strobe_off_ms'] ?? $default['strobe_off_ms']))),
            'cycle_colors' => $cycle,
            'cycle_hold_sec' => round($hold, 2),
            'fade_sec' => round(max(0, min(30, (float) ($raw['fade_sec'] ?? $default['fade_sec']))), 2),
        ];
    }

    private function normalizeColor(string $color, bool $allowAuto, string $effect): string
    {
        $c = strtolower(trim($color));
        if ($c === '' && $allowAuto) {
            return self::COLOR_AUTO;
        }
        if ($allowAuto && $c === self::COLOR_AUTO) {
            return self::COLOR_AUTO;
        }
        if ($c === self::EFFECT_RAINBOW || $effect === self::EFFECT_RAINBOW) {
            return self::EFFECT_RAINBOW;
        }

        if (preg_match('/^#[0-9a-f]{6}$/', $c) === 1) {
            return $c;
        }
        if (in_array($c, self::EVENT_COLORS, true)) {
            return $c;
        }

        return in_array($c, SpaceLight::COLORS, true) ? $c : 'white';
    }
}
