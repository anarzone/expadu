<?php

namespace App\Bureaucracy\People;

use App\Models\BureaucracyPerson;
use App\Models\BureaucracyProcessingConsent;
use App\Models\User;
use App\Privacy\ProcessingOptIns;
use Illuminate\Auth\Access\AuthorizationException;

/** Every request permission for one person's record withdrawn, as stopping its processing does (RestrictPersonProcessing). */
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
            // Composer's remembered Allow for their own record ends with it.
            ProcessingOptIns::endPaperwork($person->account_user_id);

            return $inFlight;
        });
    }
}
