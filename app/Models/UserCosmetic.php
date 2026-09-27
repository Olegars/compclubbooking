<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCosmetic extends Model
{
    protected $fillable = ['user_id', 'kind', 'ref_id', 'equipped_at'];

    protected function casts(): array
    {
        return ['equipped_at' => 'datetime'];
    }
}
