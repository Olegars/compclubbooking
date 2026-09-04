<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreAvitoChat extends Model
{
    protected $fillable = [
        'chat_id', 'avito_user_id', 'client_id', 'client_name', 'client_avatar', 'client_link',
        'ad_id', 'ad_title', 'ad_url', 'ad_price', 'config_id',
        'important', 'unread', 'last_message_at',
    ];

    protected $casts = [
        'important' => 'boolean',
        'unread' => 'boolean',
        'last_message_at' => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(StoreAvitoMessage::class, 'chat_id', 'chat_id');
    }
}
