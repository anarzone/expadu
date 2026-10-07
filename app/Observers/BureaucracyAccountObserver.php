<?php

namespace App\Observers;

use App\Bureaucracy\People\PersonDataLifecycle;
use App\Models\BureaucracyPerson;
use App\Models\User;

final class BureaucracyAccountObserver
{
    public function __construct(private PersonDataLifecycle $lifecycle) {}

    public function deleting(User $user): void
    {
        $user->setRelation('bureaucracyPersonBeingDeleted', BureaucracyPerson::query()->where('account_user_id', $user->id)->first());
    }

    public function deleted(User $user): void
    {
        $person = $user->getRelation('bureaucracyPersonBeingDeleted');
        if ($person !== null) {
            $this->lifecycle->accountWasDeleted($person->id, $user->id);
        }
        $user->unsetRelation('bureaucracyPersonBeingDeleted');
    }
}
