<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class McpToken extends Model
{
    protected $fillable = [
        'admin_id', 'user_id', 'name', 'token_hash',
        'last_used_at', 'expires_at', 'revoked_at', 'created_by',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{0: self, 1: string}
     */
    public static function issue(Admin|User $subject, string $name, ?int $days = null, ?int $createdBy = null): array
    {
        $plain = 'mcp_'.Str::random(48);
        $token = new self;
        $token->name = mb_substr(trim($name) !== '' ? trim($name) : 'cursor', 0, 80);
        $token->token_hash = hash('sha256', $plain);
        $token->created_by = $createdBy;
        if ($subject instanceof Admin) {
            $token->admin_id = $subject->id;
        } else {
            $token->user_id = $subject->id;
        }
        if ($days !== null && $days > 0) {
            $token->expires_at = now()->addDays(min($days, 365));
        }
        $token->save();

        return [$token, $plain];
    }

    public static function authenticate(string $plain): ?self
    {
        $plain = trim($plain);
        if ($plain === '' || ! str_starts_with($plain, 'mcp_') || strlen($plain) < 20) {
            return null;
        }

        $token = static::query()->where('token_hash', hash('sha256', $plain))->first();
        if (! $token || $token->revoked_at !== null) {
            return null;
        }
        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            return null;
        }

        $token->forceFill(['last_used_at' => now()])->save();

        return $token;
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }
}
