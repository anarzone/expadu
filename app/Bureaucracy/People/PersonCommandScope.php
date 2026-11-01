<?php

namespace App\Bureaucracy\People;

use App\Models\BureaucracyCase;
use App\Models\BureaucracyPerson;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PersonCommandScope
{
    public function __construct(private PersonAccess $access) {}

    public function run(User $actor, BureaucracyPerson $subject, AccessScope $scope, callable $command): mixed
    {
        return DB::transaction(function () use ($actor, $subject, $scope, $command): mixed {
            User::query()->whereKey($actor->id)->lock('for no key update')->firstOrFail();
            $person = BureaucracyPerson::query()->whereKey($subject->id)->lock('for no key update')->firstOrFail();
            $this->access->authorize($actor, $person, $scope);
            $case = BureaucracyCase::query()->where('person_id', $person->id)->where('status', 'active')->lockForUpdate()->firstOrFail();

            return $command($person, $case);
        });
    }
}
