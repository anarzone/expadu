<?php

namespace App\Ai;

use App\Models\User;

/**
 * A stable per-user bucket for an LLM feature, so the same person always
 * gets the same variant and LLM on and off can be compared. The share comes
 * from services.<feature>.rollout_percent (default 100: everyone, as before).
 */
class LlmRollout
{
    public function allows(User $user, string $feature): bool
    {
        $percent = max(0, min(100, (int) config("services.{$feature}.rollout_percent", 100)));

        return crc32("{$feature}:{$user->id}") % 100 < $percent;
    }
}
