<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameRequest extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_DONE = 'done';
    public const STATUS_REJECTED = 'rejected';

    public const SOURCE_CABINET = 'cabinet';
    public const SOURCE_SHELL = 'shell';

    protected $fillable = [
        'user_id',
        'title',
        'title_normalized',
        'comment',
        'source',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function normalizeTitle(string $title): string
    {
        $title = mb_strtolower(trim($title));
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;

        return mb_substr($title, 0, 191);
    }
}
