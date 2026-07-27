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
        return $this->availableBalance();
    }

    /**
     * Same amount admin / user cabinet show: wallet deposit (or legacy wallet.balance),
     * then users.balance fallback when the wallet row is empty.
     */
    public function availableBalance(): float
    {
        $this->loadMissing('wallet');
        $wallet = $this->wallet;

        if ($wallet) {
            $fromWallet = $wallet->depositAmount();
            if ($fromWallet > 0) {
                return $fromWallet;
            }
        }

        return (float) ($this->getAttributes()['balance'] ?? 0);
    }

    /**
     * Ensure spendable funds live on the wallet row shell/shop use.
     * Moves legacy users.balance onto wallets.deposit_balance (or wallets.balance).
     */
    public function syncBalanceToWallet(): float
    {
        $this->loadMissing('wallet');
        $wallet = $this->wallet()->firstOrCreate(['user_id' => $this->id]);

        $attrs = $wallet->getAttributes();
        $hasDepositCol = array_key_exists('deposit_balance', $attrs);
        $onWallet = $hasDepositCol
            ? (float) ($attrs['deposit_balance'] ?? 0)
            : (float) ($attrs['balance'] ?? 0);

        $legacyUser = (float) ($this->getAttributes()['balance'] ?? 0);

        if ($onWallet <= 0 && $legacyUser > 0) {
            if ($hasDepositCol) {
                $wallet->deposit_balance = $legacyUser;
            } else {
                $wallet->setAttribute('balance', $legacyUser);
            }
            $wallet->save();
            $this->forceFill(['balance' => 0])->save();
            $onWallet = $legacyUser;
        }

        return (float) $onWallet;
    }
}
