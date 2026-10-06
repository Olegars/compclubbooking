<?php

namespace App\Mcp;

use App\Models\Admin;
use App\Models\McpToken;
use App\Models\User;

final class McpActor
{
    public function __construct(
        public readonly McpToken $token,
        public readonly ?Admin $admin,
        public readonly ?User $user,
    ) {}

    public static function fromToken(McpToken $token): ?self
    {
        $token->loadMissing(['admin', 'user']);
        if ($token->admin_id && $token->user_id) {
            return null;
        }
        if ($token->admin) {
            if ($token->admin->isFired() || $token->admin->needsEmployment()) {
                return null;
            }

            return new self($token, $token->admin, null);
        }
        if ($token->user) {
            return new self($token, null, $token->user);
        }

        return null;
    }

    public function isPlayer(): bool
    {
        return $this->user !== null;
    }

    public function auditLabel(): string
    {
        if ($this->admin) {
            return 'admin:'.$this->admin->id;
        }
        if ($this->user) {
            return 'user:'.$this->user->id;
        }

        return 'token:'.$this->token->id;
    }
}
