<?php

namespace App\Support;

/**
 * Стрик Lucky Seat только с официального матча.
 * Шелл присылает поля CS2/Dota GSI; решение принимается здесь, не по флагу клиента.
 */
final class GsiStreakEligibility
{
    /** @var list<string> */
    private const CS2_RANKED_MODES = [
        'competitive',
        'premier',
        'scrimcomp2v2',
        'wingman',
    ];

    /** @var list<string> */
    private const CS2_ROUND_PHASES = [
        'live',
        'gameover',
    ];

    /**
     * @param  array<string, mixed>  $snap
     */
    public static function countsForStreak(array $snap): bool
    {
        if (filter_var($snap['has_bots'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $game = (($snap['game'] ?? 'cs2') === 'dota') ? 'dota' : 'cs2';

        return $game === 'dota' ? self::dota($snap) : self::cs2($snap);
    }

    /**
     * @param  array<string, mixed>  $snap
     */
    private static function cs2(array $snap): bool
    {
        $event = strtolower((string) ($snap['event'] ?? ''));
        if (in_array($event, ['round_win', 'round_loss'], true)) {
            $phase = strtolower(trim((string) ($snap['map_phase'] ?? '')));
            if (! in_array($phase, self::CS2_ROUND_PHASES, true)) {
                return false;
            }
        }

        $mode = strtolower(trim((string) ($snap['map_mode'] ?? '')));
        if (! in_array($mode, self::CS2_RANKED_MODES, true)) {
            return false;
        }

        return ! self::customCs2Map((string) ($snap['map'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $snap
     */
    private static function dota(array $snap): bool
    {
        if (trim((string) ($snap['custom_game'] ?? '')) !== '') {
            return false;
        }

        $matchId = trim((string) ($snap['match_id'] ?? ''));
        if (! ctype_digit($matchId)) {
            return false;
        }

        return ltrim($matchId, '0') !== '';
    }

    public static function customCs2Map(string $raw): bool
    {
        $name = strtolower(trim($raw));
        if ($name === '') {
            return true;
        }
        if (str_contains($name, 'workshop') || str_contains($name, 'aim_botz') || str_contains($name, 'aimbotz')) {
            return true;
        }

        $slash = strrpos($name, '/');
        if ($slash !== false) {
            $name = substr($name, $slash + 1);
        }
        if (str_ends_with($name, '.bsp')) {
            $name = substr($name, 0, -4);
        }

        foreach (['aim_', 'awp_', 'fy_', 'am_', 'surf_', 'bhop_', 'kz_', 'gg_', 'dm_', 'ar_', 'bot_', '1v1', 'duel_', 'zm_', 'ze_', 'jb_', 'ba_', 'mg_', 'fun_', 'custom_'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }
        if (str_starts_with($name, 'training') || str_contains($name, 'bot')) {
            return true;
        }

        return ! (str_starts_with($name, 'de_') || str_starts_with($name, 'cs_'));
    }
}
