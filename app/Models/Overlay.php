<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Overlay extends Model
{
    public const SLOTS = [
        'top_left' => 'Верхний Левый',
        'top_right' => 'Верхний Правый',
        'mid_left' => 'Средний Левый',
        'mid_right' => 'Средний Правый',
        'bottom_left' => 'Нижний Левый',
        'bottom_right' => 'Нижний Правый',
    ];

    protected $fillable = [
        'block_position',
        'title',
        'type',
        'content',
        'is_active'
    ];

    // Автоматическая конвертация JSON в массив при запросах
    protected $casts = [
        'content' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Шесть DAT-слотов терминала. Админка их только редактирует — без строк страница пустая.
     */
    public static function ensureDefaultSlots(): void
    {
        foreach (self::SLOTS as $position => $title) {
            static::query()->firstOrCreate(
                ['block_position' => $position],
                [
                    'title' => $title,
                    'type' => 'video',
                    'content' => [
                        'layers' => [
                            ['type' => 'video', 'value' => ''],
                        ],
                    ],
                    'is_active' => false,
                ]
            );
        }
    }
}
