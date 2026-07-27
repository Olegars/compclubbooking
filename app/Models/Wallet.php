<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
        $attrs = $this->attributes;
        if (array_key_exists('deposit_balance', $attrs) && $attrs['deposit_balance'] !== null) {
            return (float) $attrs['deposit_balance'];
        }

        return (float) ($attrs['balance'] ?? 0);
    }

    public function depositAmount(): float
    {
        return $this->balance;
    }
}
