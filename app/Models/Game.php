<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Game extends Model
{
    protected $fillable = ['title', 'platform', 'category', 'poster', 'exe_path', 'launch_args'];

    public function accounts()
    {
        return $this->hasMany(GameAccount::class);
    }
}
