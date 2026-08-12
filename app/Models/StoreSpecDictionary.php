<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class StoreSpecDictionary extends Model
{
    protected $table = 'store_spec_dictionary';

    protected $fillable = [
        'club_id', 'dict_key', 'value',
    ];

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    /** @return list<string> */
    public static function valuesFor(int $clubId, string $dictKey): array
    {
        return self::query()
            ->where('club_id', $clubId)
            ->where('dict_key', $dictKey)
            ->orderBy('value')
            ->pluck('value')
            ->all();
    }

    /**
     * Сохранить значения specs в словарь клуба (новые бренды/модели и т.п.).
     *
     * @param  array<string, mixed>  $specs
     */
    public static function rememberFromSpecs(int $clubId, string $type, array $specs): void
    {
        $schema = \App\Support\StoreComponentSpecs::schemas()[$type] ?? [];
        if ($schema === []) {
            return;
        }

        $now = now();
        $rows = [];
        foreach ($schema as $field) {
            $dictKey = $field['suggest'] ?? null;
            $specKey = $field['key'] ?? null;
            if (! is_string($dictKey) || ! is_string($specKey)) {
                continue;
            }
            $value = trim((string) ($specs[$specKey] ?? ''));
            if ($value === '' || mb_strlen($value) > 128) {
                continue;
            }
            $rows[] = [
                'club_id' => $clubId,
                'dict_key' => $dictKey,
                'value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        // MySQL: ignore duplicates on unique
        DB::table('store_spec_dictionary')->insertOrIgnore($rows);
    }

    /**
     * Сид-словари + значения, сохранённые при приходе на склад.
     *
     * @return array<string, list<string>>
     */
    public static function mergedDictionaries(int $clubId): array
    {
        $merged = \App\Support\StoreComponentSpecs::dictionaries();
        $learned = self::query()
            ->where('club_id', $clubId)
            ->orderBy('value')
            ->get(['dict_key', 'value']);

        foreach ($learned as $row) {
            $key = $row->dict_key;
            if (! isset($merged[$key])) {
                $merged[$key] = [];
            }
            if (! in_array($row->value, $merged[$key], true)) {
                $merged[$key][] = $row->value;
            }
        }

        foreach ($merged as $key => $list) {
            $merged[$key] = array_values($list);
        }

        return $merged;
    }
}
