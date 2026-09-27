<?php

namespace App\Services\Faceit;

use App\Models\User;

/**
 * CS2 LFG: skill_level 1–10 вместо заглушки faceit → 7.
 */
class FaceitRankBridge
{
    public function __construct(private readonly FaceitIdentityService $faceit)
    {
    }

    public function tierFor(User $user, ?int $clubId): ?int
    {
        if ($this->faceit->mode($clubId) === 'off') {
            return null;
        }
        $block = $this->faceit->shellBlock($user, $clubId);
        if (! $block || empty($block['linked'])) {
            return null;
        }
        if (! empty($block['banned'])) {
            throw new \RuntimeException('FACEIT бан');
        }
        $level = (int) ($block['skill_level'] ?? 0);
        if ($level < 1) {
            return null;
        }

        return max(1, min(10, $level));
    }
}
