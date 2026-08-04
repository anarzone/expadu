<?php

namespace App\Bureaucracy\Cases;

use App\Bureaucracy\Facts\LegacyFactBootstrapper;
use App\Models\BureaucracyCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CurrentCasePlan
{
    public function __construct(
        private LegacyFactBootstrapper $factBootstrapper,
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

        return DB::transaction(function () use ($case): array {
            $lockedCase = BureaucracyCase::query()
                ->whereKey($case->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $snapshot = $this->snapshotStore->store($lockedCase);
            $question = $this->questionSelector->select($lockedCase);

            return $this->presenter->present($lockedCase, $snapshot, $question);
        });
    }
}
