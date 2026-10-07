<?php

namespace App\Bureaucracy\Ai;

use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Bureaucracy\People\PersonCommandScope;
use App\Models\BureaucracyExtractionCandidate;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyProcessingConsent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class WithdrawPersonProcessing
{
    public function __construct(private PersonCommandScope $scope, private PersonAccess $access) {}

    public function execute(User $actor, BureaucracyPerson $person): int
    {
        return $this->scope->run($actor, $person, AccessScope::ViewPlan, function ($person, $case) use ($actor): int {
            if (! $this->access->canManage($actor, $person)) {
                throw new AuthorizationException;
            }
            $permissions = BureaucracyProcessingConsent::query()->where('case_id', $case->id);
            $inFlight = (clone $permissions)->where('state', 'processing')->whereNotNull('dispatched_at')->count();
            $permissions->update(['result' => null, 'withdrawn_at' => now()->utc(), 'state' => 'withdrawn']);
            BureaucracyExtractionCandidate::query()->where('case_id', $case->id)->update(['value' => null, 'confirmation_token' => null, 'state' => 'invalidated']);

            return $inFlight;
        });
    }
}
