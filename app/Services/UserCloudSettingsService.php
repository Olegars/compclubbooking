<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Cloud Saves: per-player game settings pack (CS2 sens, Valorant cfg, …).
 *
 * Shell collects files from disk on logout / mid-session and pushes a JSON pack.
 * On login (any PC) Shell receives the pack and writes files back.
 *
 * Expected payload shape (flexible; Shell owns path mapping):
 * {
 *   "version": 1,
 *   "games": {
 *     "cs2": {
 *       "files": { "cs2_user_convars_0_slot0.vcfg": "<text or base64>" },
 *       "meta":  { "sens": 1.25 }
 *     },
 *     "valorant": {
 *       "files": { "GameUserSettings.ini": "..." }
 *     }
 *   }
 * }
 */
class UserCloudSettingsService
{
    public const PAYLOAD_VERSION = 1;

    /** Soft limit (~2 MB of JSON) to avoid oversized Shell uploads. */
    public const MAX_PAYLOAD_BYTES = 2_000_000;

    public function getPack(User $user): ?array
    {
        $row = $user->settings()->first();
        if (!$row || !is_array($row->payload) || $row->payload === []) {
            return null;
        }

        return $row->payload;
    }

    /**
     * @return array{payload: array, updated_at: string|null}
     */
    public function getPackWithMeta(User $user): array
    {
        $row = $user->settings()->first();
        $payload = (is_array($row?->payload) && $row->payload !== []) ? $row->payload : null;

        return [
            'payload' => $payload,
            'updated_at' => $row?->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Upsert full settings pack for the player.
     *
     * @param  array<string, mixed>  $pack
     */
    public function savePack(User $user, array $pack): UserSetting
    {
        $normalized = $this->normalizePack($pack);
        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException(
                'Пак настроек слишком большой (макс. '.self::MAX_PAYLOAD_BYTES.' байт).'
            );
        }

        $setting = UserSetting::updateOrCreate(
            ['user_id' => $user->id],
            ['payload' => $normalized]
        );

        Log::info('Cloud settings saved', [
            'user_id' => $user->id,
            'bytes' => strlen($encoded),
            'games' => array_keys($normalized['games'] ?? []),
        ]);

        return $setting;
    }

    /**
     * Deep-merge game entries into existing pack (partial update by game_key).
     *
     * @param  array<string, mixed>  $partial
     */
    public function mergePack(User $user, array $partial): UserSetting
    {
        $existing = $this->getPack($user) ?? [
            'version' => self::PAYLOAD_VERSION,
            'games' => [],
        ];

        $incoming = $this->normalizePack($partial);
        $games = $existing['games'] ?? [];
        if (!is_array($games)) {
            $games = [];
        }

        foreach ($incoming['games'] as $gameKey => $gameData) {
            $games[$gameKey] = $gameData;
        }

        return $this->savePack($user, [
            'version' => max(
                (int) ($existing['version'] ?? self::PAYLOAD_VERSION),
                (int) ($incoming['version'] ?? self::PAYLOAD_VERSION)
            ),
            'games' => $games,
            'synced_at' => $incoming['synced_at'] ?? now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $pack
     * @return array{version: int, games: array<string, mixed>, synced_at: string}
     */
    public function normalizePack(array $pack): array
    {
        $games = $pack['games'] ?? null;
        if (!is_array($games)) {
            // Allow Shell to send flat { "cs2": {...}, "valorant": {...} } without wrapper.
            $games = [];
            foreach ($pack as $key => $value) {
                if (in_array($key, ['version', 'synced_at', 'games', 'meta'], true)) {
                    continue;
                }
                if (is_array($value)) {
                    $games[(string) $key] = $value;
                }
            }
        }

        if ($games === []) {
            throw new InvalidArgumentException('Пак настроек пуст: нужен объект games или записи по играм.');
        }

        $cleanGames = [];
        foreach ($games as $gameKey => $gameData) {
            $key = strtolower(trim((string) $gameKey));
            if ($key === '' || !is_array($gameData)) {
                continue;
            }
            $cleanGames[$key] = $gameData;
        }

        if ($cleanGames === []) {
            throw new InvalidArgumentException('Пак настроек не содержит валидных игр.');
        }

        return [
            'version' => (int) ($pack['version'] ?? self::PAYLOAD_VERSION),
            'games' => $cleanGames,
            'synced_at' => isset($pack['synced_at']) && is_string($pack['synced_at'])
                ? $pack['synced_at']
                : now()->toIso8601String(),
        ];
    }
}
