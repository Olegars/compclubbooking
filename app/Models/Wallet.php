<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Wallet extends Model {
    protected $fillable = ['user_id', 'deposit_balance', 'bonus_balance', 'total_spent'];

    protected $appends = ['balance'];

    protected function casts(): array
    {
        return [
            'deposit_balance' => 'float',
            'bonus_balance' => 'float',
            'total_spent' => 'float',
        ];
    }

    public function user() {
        return $this->belongsTo(User::class);
    }

    /**
     * UI / legacy alias: prefer deposit_balance, fall back to old wallets.balance column.
     */
    public function getBalanceAttribute(): float
    {
        return $this->depositAmount();
    }

    /**
     * Spendable club money on this wallet row (deposit, or legacy balance column).
     */
    public function depositAmount(): float
    {
        $attrs = $this->attributes;
        $deposit = array_key_exists('deposit_balance', $attrs)
            ? (float) ($attrs['deposit_balance'] ?? 0)
            : null;
        $legacy = array_key_exists('balance', $attrs)
            ? (float) ($attrs['balance'] ?? 0)
            : 0.0;

        if ($deposit === null) {
            return $legacy;
        }

        // Partial migrate: deposit_balance present but money still on old column.
        return $deposit > 0 ? $deposit : $legacy;
    }

    public static function hasDepositColumn(): bool
    {
        static $cached = null;
        if ($cached === null) {
            $cached = Schema::hasColumn('wallets', 'deposit_balance');
        }
        return $cached;
    }

    public static function hasLegacyBalanceColumn(): bool
    {
        static $cached = null;
        if ($cached === null) {
            $cached = Schema::hasColumn('wallets', 'balance');
        }
        return $cached;
    }

    /**
     * Merge leftover wallets.balance into deposit_balance after partial renames.
     */
    protected function coalesceLegacyBalanceColumn(): void
    {
        if (!static::hasDepositColumn() || !static::hasLegacyBalanceColumn()) {
            return;
        }

        $leftover = (float) (DB::table('wallets')->where('id', $this->id)->value('balance') ?? 0);
        if ($leftover > 0) {
            DB::table('wallets')->where('id', $this->id)->update([
                'deposit_balance' => DB::raw('COALESCE(deposit_balance, 0) + ' . $leftover),
                'balance' => 0,
            ]);
        }
    }

    /**
     * Credit spendable funds without Eloquent increment('balance') —
     * that path breaks when getBalanceAttribute() exists and the column was renamed.
     */
    public function creditSpendable(float $amount): float
    {
        $amount = abs((float) $amount);
        if ($amount <= 0) {
            return $this->depositAmount();
        }

        $this->coalesceLegacyBalanceColumn();

        if (static::hasDepositColumn()) {
            DB::table('wallets')->where('id', $this->id)->increment('deposit_balance', $amount);
        } else {
            DB::table('wallets')->where('id', $this->id)->increment('balance', $amount);
        }

        $this->refresh();
        return $this->depositAmount();
    }

    /**
     * Debit spendable funds without Eloquent decrement('balance') —
     * that path breaks when getBalanceAttribute() exists and the column was renamed.
     */
    public function debitSpendable(float $amount): float
    {
        $amount = abs((float) $amount);
        if ($amount <= 0) {
            return $this->depositAmount();
        }

        $this->coalesceLegacyBalanceColumn();

        if (static::hasDepositColumn()) {
            DB::table('wallets')->where('id', $this->id)->decrement('deposit_balance', $amount);
        } else {
            DB::table('wallets')->where('id', $this->id)->decrement('balance', $amount);
        }

        $this->refresh();
        return $this->depositAmount();
    }
}
