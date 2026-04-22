<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = ['user_id', 'amount', 'type', 'source', 'description', 'payload'];
    protected $casts = ['payload' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
