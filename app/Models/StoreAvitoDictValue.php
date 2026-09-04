<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreAvitoDictValue extends Model
{
    protected $fillable = ['tag', 'value', 'parent_value', 'sort_order'];
}
