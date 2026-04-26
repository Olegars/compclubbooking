<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Wallet extends Model {
    protected $fillable = ['user_id', 'deposit_balance', 'bonus_balance', 'total_spent'];

    public function user() {
        return $this->belongsTo(User::class);
    }
}
