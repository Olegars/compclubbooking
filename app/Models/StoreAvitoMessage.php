<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreAvitoMessage extends Model
{
    protected $fillable = [
        'chat_id', 'avito_message_id', 'author_id', 'type', 'content',
        'from_us', 'read', 'avito_created_at',
    ];

    protected $casts = [
        'content' => 'array',
        'from_us' => 'boolean',
        'read' => 'boolean',
        'avito_created_at' => 'datetime',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(StoreAvitoChat::class, 'chat_id', 'chat_id');
    }

    public function text(): string
    {
        $content = $this->content;
        if (! is_array($content)) {
            return '';
        }

        return trim((string) ($content['text'] ?? ''));
    }
}
