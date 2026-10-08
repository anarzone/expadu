<?php

namespace App\Policies;

use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Models\BureaucracyPerson;
use App\Models\User;

final class BureaucracyPersonPolicy
{
    public function __construct(private PersonAccess $access) {}

    public function view(User $actor, BureaucracyPerson $person): bool
    {
        return $this->access->scopesFor($actor, $person) !== [];
    }

    public function viewFacts(User $actor, BureaucracyPerson $person): bool
    {
        return $this->access->allows($actor, $person, AccessScope::ViewFacts);
    }

    public function update(User $actor, BureaucracyPerson $person): bool
    {
        return $this->access->allows($actor, $person, AccessScope::EditFacts);
    }

    public function manageSharing(User $actor, BureaucracyPerson $person): bool
    {
        return $this->access->canManage($actor, $person);
    }

    public function delete(User $actor, BureaucracyPerson $person): bool
    {
        return $this->access->canManage($actor, $person);
    }
}
