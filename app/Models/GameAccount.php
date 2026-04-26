<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class GameAccount extends Model
{
    protected $fillable = ['game_id', 'login', 'password', 'status', 'current_pc_id'];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }
}
