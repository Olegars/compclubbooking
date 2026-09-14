<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StoreAvitoChat extends Model
{
    public const WORKFLOW_INBOX = 'inbox';

    public const WORKFLOW_IN_PROGRESS = 'in_progress';

    public const WORKFLOW_DONE = 'done';

    public const FOLDER_FAVORITE = 'favorite';

    public const FOLDERS = [
        self::WORKFLOW_INBOX,
        self::WORKFLOW_IN_PROGRESS,
        self::WORKFLOW_DONE,
        self::FOLDER_FAVORITE,
    ];

    protected $fillable = [
        'chat_id', 'avito_user_id', 'client_id', 'client_name', 'client_avatar', 'client_link',
        'ad_id', 'ad_title', 'ad_url', 'ad_price', 'config_id',
        'important', 'unread', 'workflow', 'accepted_by_id', 'accepted_at', 'done_at', 'last_message_at',
    ];

    protected $casts = [
        'important' => 'boolean',
        'unread' => 'boolean',
        'accepted_at' => 'datetime',
        'done_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(StoreAvitoMessage::class, 'chat_id', 'chat_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'accepted_by_id');
    }
}
