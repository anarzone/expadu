<?php

namespace App\Bureaucracy\Evidence;

use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Bureaucracy\ReadModel\PlanReadModel;
use App\Models\BureaucracyPerson;
use App\Models\User;

final class PaperworkReadModel
{
    public function __construct(private PlanReadModel $plans, private PersonAccess $access) {}

    public function for(User $actor, BureaucracyPerson $person, string $jurisdiction): array
    {
        $this->access->authorize($actor, $person, AccessScope::ManageEvidence);

        return $this->plans->for($actor, $person, $jurisdiction)['paperwork'];
    }
}
