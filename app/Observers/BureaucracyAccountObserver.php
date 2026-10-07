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
        $user->setRelation('bureaucracyDependentsBeingOrphaned', collect($this->lifecycle->dependentsGuardedBy($user->id)));
    }

    public function deleted(User $user): void
    {
        $person = $user->getRelation('bureaucracyPersonBeingDeleted');
        if ($person !== null) {
            $this->lifecycle->accountWasDeleted($person->id, $user->id);
        }
        $user->unsetRelation('bureaucracyPersonBeingDeleted');
        // The guardian's authority rows cascaded with the account; a dependent nobody else
        // may manage would otherwise stay active with no one able to see or erase it.
        $dependents = $user->relationLoaded('bureaucracyDependentsBeingOrphaned') ? $user->getRelation('bureaucracyDependentsBeingOrphaned') : collect();
        $user->unsetRelation('bureaucracyDependentsBeingOrphaned');
        foreach ($dependents as $dependentId) {
            $this->lifecycle->eraseUnguardedDependent($dependentId);
        }
    }
}
