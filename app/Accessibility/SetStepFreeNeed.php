<?php

namespace App\Accessibility;

use App\Models\User;

/**
 * The person's step-free need (users.needs_step_free, owner 21 Oct 2026): Travel's Plan a
 * trip starts with Step-free on, the trip card treats a lift out as no step-free way, and
 * Composer plans only step-free ways. Hidden from the user's serialisation and never sent
 * to the AI. The one writer: the profile's control and Composer's set_needs both set it here.
 */
final class SetStepFreeNeed
{
    public function execute(User $user, bool $needed): void
    {
        $user->forceFill(['needs_step_free' => $needed])->save();
    }
}
