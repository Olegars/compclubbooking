<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tournament extends Model
{
    // Разрешаем массовое заполнение этих полей
    protected $fillable = [
        'name',
        'game_id',
        'start_at',
        'end_at',
        'entry_fee',
        'prize_pool',
        'status'
    ];

    // Приводим даты в правильный формат Carbon
    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    /**
     * Связь: Турнир принадлежит одной Игре (1 к 1 в рамках турнира)
     */
    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * Связь: В турнире участвует много Компьютеров (Многие-ко-многим)
     */
    public function computers()
    {
        // Явно указываем имя таблицы связей
        return $this->belongsToMany(Computer::class, 'tournament_computer');
    }
}
