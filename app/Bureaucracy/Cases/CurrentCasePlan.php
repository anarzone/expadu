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
        private PendingAnswers $pendingAnswers,
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

            // The plan asks first, because a question it raises can change what
            // the plan asserts. Only when it has nothing left do we fall back to
            // the wider sweep — which finds facts gating published-but-unapproved
            // branches, the ones nothing else in the app would ever ask for.
            $question = $this->questionSelector->select($lockedCase)
                ?? $this->questionSelector->ask(
                    $lockedCase,
                    $this->pendingAnswers->forCase($lockedCase),
                );

            return $this->presenter->present($lockedCase, $snapshot, $question);
        });
    }
}
