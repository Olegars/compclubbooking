<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class GameAccount extends Model
{
    protected $fillable = [
        'game_id', 'login', 'password', 'status', 'current_pc_id',
        'shared_secret', 'persona_name',
        'config_vdf', 'loginusers_vdf', 'local_vdf' // Наша обнова
    ];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }
}
