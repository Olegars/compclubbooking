<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;
    protected $appends = ['total_balance'];

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar',
        'balance' // Добавь, если используешь локальный баланс
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // Связь с кошельком
    public function wallet() {
        return $this->hasOne(Wallet::class);
    }

    // Связь с транзакциями
    public function transactions() {
        return $this->hasMany(Transaction::class);
    }

    public function settings()
    {
        return $this->hasOne(UserSetting::class);
    }

    public function gameStats()
    {
        return $this->hasMany(UserGameStat::class);
    }

    // --- НОВЫЕ СВЯЗИ ДЛЯ REACTOR ---

    /**
     * Ищем активную сессию в Gizmo (через твою таблицу сессий)
     */
    public function activeSession() {
        // Предполагаем, что у тебя есть таблица или модель GizmoSession
        // и в ней есть поле host_name (номер ПК)
        return $this->hasOne(GizmoSession::class)->where('is_active', true);
    }

    /**
     * Ищем все бронирования пользователя
     */
    public function bookings() {
        return $this->hasMany(Booking::class);
    }
    // Добавь это в модель User.php
    public function getTotalBalanceAttribute()
    {
        // Берем из кошелька, если там пусто - берем из таблицы users
        return (float)($this->wallet?->deposit_balance ?? $this->balance ?? 0);
    }
}
