<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

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
     * Moves legacy users.balance and leftover wallets.balance onto deposit_balance.
     */
    public function syncBalanceToWallet(): float
    {
        $wallet = $this->wallet()->firstOrCreate(['user_id' => $this->id]);
        $hasDepositCol = Wallet::hasDepositColumn();
        $hasLegacyWalletCol = Wallet::hasLegacyBalanceColumn();

        // Merge leftover wallets.balance into deposit_balance after partial renames.
        if ($hasDepositCol && $hasLegacyWalletCol) {
            $leftover = (float) (DB::table('wallets')->where('id', $wallet->id)->value('balance') ?? 0);
            if ($leftover > 0) {
                DB::table('wallets')->where('id', $wallet->id)->update([
                    'deposit_balance' => DB::raw('COALESCE(deposit_balance, 0) + ' . $leftover),
                    'balance' => 0,
                ]);
            }
        }

        $wallet->refresh();
        $attrs = $wallet->getAttributes();
        $onWallet = $hasDepositCol
            ? (float) ($attrs['deposit_balance'] ?? 0)
            : (float) ($attrs['balance'] ?? 0);

        $legacyUser = (float) ($this->getAttributes()['balance'] ?? 0);

        if ($onWallet <= 0 && $legacyUser > 0) {
            if ($hasDepositCol) {
                DB::table('wallets')->where('id', $wallet->id)->update([
                    'deposit_balance' => $legacyUser,
                ]);
            } else {
                DB::table('wallets')->where('id', $wallet->id)->update([
                    'balance' => $legacyUser,
                ]);
            }
            $this->forceFill(['balance' => 0])->save();
            $onWallet = $legacyUser;
            $wallet->refresh();
        }

        $this->setRelation('wallet', $wallet);

        return (float) $onWallet;
    }
}
