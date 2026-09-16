<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ClubFeature;
use App\Models\Computer;
use App\Models\User;
use App\Support\ClubFeatureCatalog;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ClubFeatureService
{
    /** @var array<string, array<string, array{enabled:bool, settings:array<string, mixed>}>> */
    private array $cache = [];

    public function enabled(?int $clubId, string $key): bool
    {
        return $this->resolved($clubId, $key)['enabled'];
    }

    public function int(?int $clubId, string $key, string $setting, ?int $fallback = null): int
    {
        $value = $this->setting($clubId, $key, $setting);
        if (is_numeric($value)) {
            return (int) round((float) $value);
        }

        return $fallback ?? 0;
    }

    public function float(?int $clubId, string $key, string $setting, ?float $fallback = null): float
    {
        $value = $this->setting($clubId, $key, $setting);
        if (is_numeric($value)) {
            return (float) $value;
        }

        return $fallback ?? 0.0;
    }

    public function bool(?int $clubId, string $key, string $setting, bool $fallback = false): bool
    {
        $value = $this->setting($clubId, $key, $setting);
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null) {
            return $fallback;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function setting(?int $clubId, string $key, string $setting): mixed
    {
        $settings = $this->resolved($clubId, $key)['settings'];

        return $settings[$setting] ?? ClubFeatureCatalog::defaultSettings($key)[$setting] ?? null;
    }

    /**
     * @return array{enabled:bool, settings:array<string, mixed>}
     */
    public function resolved(?int $clubId, string $key): array
    {
        $defaults = [
            'enabled' => true,
            'settings' => ClubFeatureCatalog::defaultSettings($key),
        ];
        if (! ClubFeatureCatalog::get($key)) {
            return $defaults;
        }
        $clubId = $clubId && $clubId > 0 ? $clubId : 0;
        $pack = $this->packFor($clubId);

        return $pack[$key] ?? $defaults;
    }

    /**
     * @return array<string, bool>
     */
    public function enabledMap(?int $clubId): array
    {
        $out = [];
        foreach (ClubFeatureCatalog::keys() as $key) {
            $out[$key] = $this->enabled($clubId, $key);
        }

        return $out;
    }

    /**
     * Компактный JSON для шелла: ключ → {enabled, …settings}.
     *
     * @return array<string, array<string, mixed>>
     */
    public function shellPayload(?int $clubId): array
    {
        $out = [];
        foreach (ClubFeatureCatalog::keys() as $key) {
            $row = $this->resolved($clubId, $key);
            $out[$key] = array_merge(['enabled' => $row['enabled']], $row['settings']);
        }

        return $out;
    }

    public function shellPayloadForComputer(?Computer $computer): array
    {
        return $this->shellPayload($this->clubIdForComputer($computer));
    }

    public function clubIdForComputer(?Computer $computer): ?int
    {
        return $computer && $computer->club_id ? (int) $computer->club_id : null;
    }

    public function clubIdForUser(?User $user): ?int
    {
        if (! $user) {
            return null;
        }
        $clubId = Computer::query()
            ->whereIn('id', Booking::query()->where('user_id', $user->id)->select('computer_id'))
            ->value('club_id');

        return $clubId ? (int) $clubId : null;
    }

    public function enabledForComputer(?Computer $computer, string $key): bool
    {
        return $this->enabled($this->clubIdForComputer($computer), $key);
    }

    public function enabledForUser(?User $user, string $key): bool
    {
        return $this->enabled($this->clubIdForUser($user), $key);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function adminPayload(?int $clubId): array
    {
        $groups = ClubFeatureCatalog::groups();
        $out = [];
        foreach (ClubFeatureCatalog::all() as $def) {
            $row = $this->resolved($clubId, $def['key']);
            $out[] = [
                'key' => $def['key'],
                'title' => $def['title'],
                'description' => $def['description'],
                'group' => $def['group'],
                'group_title' => $groups[$def['group']] ?? $def['group'],
                'icon' => $def['icon'],
                'admin_path' => $def['admin_path'],
                'enabled' => $row['enabled'],
                'settings' => $row['settings'],
                'fields' => $def['fields'],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function save(?int $clubId, string $key, bool $enabled, ?array $settings = null): ClubFeature
    {
        if (! $clubId || $clubId < 1) {
            throw new RuntimeException('Нет локации для сохранения фич');
        }
        if (! ClubFeatureCatalog::get($key)) {
            throw new RuntimeException('Неизвестная фича');
        }

        $row = ClubFeature::query()->firstOrNew([
            'club_id' => $clubId,
            'key' => $key,
        ]);
        $row->enabled = $enabled;
        if ($settings !== null) {
            $row->settings = ClubFeatureCatalog::sanitizeSettings($key, $settings);
        } elseif (! is_array($row->settings)) {
            $row->settings = ClubFeatureCatalog::defaultSettings($key);
        }
        $row->save();
        unset($this->cache[(string) $clubId]);

        return $row;
    }

    public function assertEnabled(?int $clubId, string $key, string $message = 'Фича выключена'): void
    {
        if (! $this->enabled($clubId, $key)) {
            throw new RuntimeException($message);
        }
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * @return array<string, array{enabled:bool, settings:array<string, mixed>}>
     */
    private function packFor(int $clubId): array
    {
        $cacheKey = (string) $clubId;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $pack = [];
        foreach (ClubFeatureCatalog::all() as $def) {
            $pack[$def['key']] = [
                'enabled' => true,
                'settings' => ClubFeatureCatalog::defaultSettings($def['key']),
            ];
        }

        if ($clubId > 0 && Schema::hasTable('club_features')) {
            $rows = ClubFeature::query()
                ->where('club_id', $clubId)
                ->get();
            foreach ($rows as $row) {
                $key = (string) $row->key;
                if (! isset($pack[$key])) {
                    continue;
                }
                $pack[$key]['enabled'] = (bool) $row->enabled;
                $stored = is_array($row->settings) ? $row->settings : [];
                $pack[$key]['settings'] = array_merge($pack[$key]['settings'], ClubFeatureCatalog::sanitizeSettings($key, $stored));
            }
        }

        $this->cache[$cacheKey] = $pack;

        return $pack;
    }
}
