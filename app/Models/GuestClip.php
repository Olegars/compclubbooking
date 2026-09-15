<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class GuestClip extends Model
{
    protected $fillable = [
        'user_id', 'booking_id', 'computer_id', 'path', 'bytes',
        'duration_sec', 'aspect', 'source', 'share_token', 'telegram_sent_at', 'telegram_error',
    ];

    protected $casts = [
        'bytes' => 'integer',
        'duration_sec' => 'integer',
        'telegram_sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }

    public function publicUrl(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    public function shareUrl(): string
    {
        return url('/clips/'.$this->share_token);
    }
}
