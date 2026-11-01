<?php

namespace App\Observers;

use App\Models\User;
use App\Personalisation\SignalRecorder;

/**
 * The ledger and aggregates sit under a pseudonym, so no foreign key cascades
 * them: deleting the account erases them here.
 */
final class PersonalisationAccountObserver
{
    public function __construct(private SignalRecorder $signals) {}

    public function deleted(User $user): void
    {
        $this->signals->forget($user);
    }
}
