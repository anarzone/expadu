<?php

namespace App\Bureaucracy\ReadModel;

use App\Bureaucracy\Verification\Escalations;
use App\Bureaucracy\Verification\UnansweredPlan;
use App\ContextEngine\ActionBus;
use App\ContextEngine\Evaluators\BureaucracyEvaluator;
use App\Models\BureaucracyPerson;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Plans are read fresh, not cached. Only the derived action queue needs regeneration. */
class ReassessPerson
{
    public function __construct(private ActionBus $bus, private BureaucracyEvaluator $evaluator,
        private ReassessmentEvents $refresh, private AccountHolderPlan $plans, private Escalations $escalations) {}

    public function execute(int $personId): void
    {
        $userId = BureaucracyPerson::query()->whereKey($personId)->value('account_user_id');
        if ($userId === null) {
            return; // Delegation does not authorise notifications on someone else's behalf.
        }
        DB::transaction(function () use ($userId, $personId): void {
            $user = User::query()->whereKey($userId)->whereNotNull('email_verified_at')->whereNotNull('onboarded_at')
                ->lock('for no key update')->first();
            $person = BureaucracyPerson::query()->whereKey($personId)->where('record_status', 'active')->sharedLock()->first();
            if ($user === null || $person?->account_user_id !== $user->id) {
                return;
            }
            $plan = $this->plans->for($user);
            $this->refresh->atBoundary($person, $plan['next_reassessment_at'] ?? null);
            $gaps = UnansweredPlan::processes($plan);
            // Cache reservations and external queues cannot roll back with PostgreSQL.
            // Wait for the outer commit, then re-read the current authorised plan.
            DB::afterCommit(function () use ($userId, $gaps, $plan): void {
                foreach ($gaps as $definition) {
                    $this->escalations->raise('no_verified_answer', $definition, Escalations::High,
                        "Someone's plan needs \"{$definition}\" but it has no verified content right now.");
                }
                if (UnansweredPlan::isEmpty($plan)) {
                    $this->escalations->raise('empty_plan', (string) ($plan['jurisdiction'] ?? 'unknown'), Escalations::High,
                        'Someone finished onboarding and the app found nothing it could answer for them.');
                }
                $user = User::query()->whereKey($userId)->whereNotNull('email_verified_at')->whereNotNull('onboarded_at')->first();
                if ($user !== null) {
                    $this->bus->removeTypes($user->id, ['bureaucracy_task', 'permanent_residency_eligible']);
                    $this->evaluator->evaluate($user);
                }
            });
        });
    }
}
