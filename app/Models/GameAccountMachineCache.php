<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameAccountMachineCache extends Model
{
    protected $table = 'game_account_machine_caches';

    protected $fillable = [
        'game_account_id',
        'computer_id',
        'config_vdf',
        'loginusers_vdf',
        'local_vdf',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(GameAccount::class, 'game_account_id');
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class, 'computer_id');
    }
}
