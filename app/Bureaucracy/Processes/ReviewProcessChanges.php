<?php

namespace App\Bureaucracy\Processes;

use App\Bureaucracy\Assessment\PrepareAssessmentInput;
use App\Models\BureaucracyProcess;
use App\Models\BureaucracyProcessEvent;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ReviewProcessChanges
{
    public function __construct(private ProcessMutation $mutation, private PrepareAssessmentInput $inputs,
        private DiscoverProcesses $discover, private RebaseProcessState $states) {}

    public function execute(User $actor, BureaucracyProcess $process, int $expectedVersion, string $requestId, string $reviewToken, ?string $bindOccurrence = null): BureaucracyProcessEvent
    {
        return $this->mutation->execute($actor, $process, $expectedVersion, $requestId, ['review_changes', $reviewToken, $bindOccurrence], function ($current, $person) use ($actor, $reviewToken, $bindOccurrence): array {
            $proposals = $this->discover->for($this->inputs->for($actor, $person, $current->jurisdiction));
            $proposal = array_column($proposals, null, 'occurrence_key')[$bindOccurrence ?? $current->occurrence_key] ?? null;
            if ($proposal === null || $proposal['definition_id'] !== $current->definition_id || ! hash_equals($proposal['review_token'], $reviewToken)) {
                throw new ConflictHttpException('The plan changed again. Review the latest version.');
            }
            if ($bindOccurrence !== null && ($current->context_id !== 'unbound:'.$proposal['occurrence_fact']
                || str_starts_with($proposal['context_id'], 'unbound:')
                || BureaucracyProcess::query()->where('case_id', $current->case_id)->where('occurrence_key', $bindOccurrence)->exists())) {
                throw new ConflictHttpException('This occurrence cannot inherit that history. Keep the separate process records.');
            }
            $state = $this->states->for($current->state, $current->step_definitions, $proposal['steps']);

            return ['event' => ['type' => 'guidance_rebased', 'payload' => [
                'provenance' => 'user_confirmed_review', 'previous_catalogue_hash' => $current->catalogue_hash,
                'catalogue_hash' => $proposal['catalogue_hash'], 'previous_steps' => $current->step_definitions,
                'steps' => $proposal['steps'], 'previous_state' => $current->state,
                'previous_occurrence_key' => $current->occurrence_key, 'occurrence_key' => $proposal['occurrence_key'],
            ]], 'updates' => ['state' => $state, 'step_definitions' => $proposal['steps'], 'catalogue_hash' => $proposal['catalogue_hash'],
                'occurrence_key' => $proposal['occurrence_key'], 'context_id' => $proposal['context_id']]];
        });
    }
}
