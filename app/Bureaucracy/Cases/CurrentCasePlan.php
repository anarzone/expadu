<?php

namespace App\Bureaucracy\Cases;

use App\Bureaucracy\Facts\LegacyFactBootstrapper;
use App\Models\User;

final class CurrentCasePlan
{
    public function __construct(
        private LegacyFactBootstrapper $factBootstrapper,
        private CaseMatcher $caseMatcher,
        private PlanSnapshotStore $snapshotStore,
        private QuestionSelector $questionSelector,
        private CasePlanPresenter $presenter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $case = $this->factBootstrapper->bootstrap($user);
        $match = $this->caseMatcher->match($case);
        $snapshot = $this->snapshotStore->store($case);
        $question = $this->questionSelector->select($case, $match);

        return $this->presenter->present($case, $snapshot, $question);
    }
}
