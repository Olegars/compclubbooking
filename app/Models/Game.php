<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    protected $fillable = ['title', 'platform', 'category', 'poster', 'exe_path', 'launch_args'];

    public function accounts(): HasMany
    {
        return $this->hasMany(GameAccount::class);
    }

    public function clubOffers(): HasMany
    {
        return $this->hasMany(ClubGame::class);
    }

    public function installations(): HasMany
    {
        return $this->hasMany(ComputerGame::class);
    }
}
