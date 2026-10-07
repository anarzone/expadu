<?php

namespace App\Bureaucracy\Processes;

use App\Bureaucracy\Assessment\AssessmentInput;
use App\Bureaucracy\Catalogue\CatalogueHash;

final class ProjectProcess
{
    /** Pure workflow view shared by the overview and the process detail. */
    public function for(array $stored, AssessmentInput $input, array $proposals): array
    {
        $proposal = collect($proposals)->firstWhere('occurrence_key', $stored['occurrence_key']);
        $binding = false;
        if ($proposal === null && str_starts_with($stored['context_id'], 'unbound:')) {
            $candidates = array_values(array_filter($proposals, fn ($candidate) => $candidate['definition_id'] === $stored['definition_id']
                && $stored['context_id'] === 'unbound:'.$candidate['occurrence_fact']
                && ! in_array($candidate['occurrence_key'], array_column($input->processes, 'occurrence_key'), true)));
            if (count($candidates) === 1) {
                $proposal = $candidates[0];
                $binding = true;
            }
        }
        $review = $proposal !== null && ($binding || $stored['catalogue_hash'] !== $proposal['catalogue_hash']
            || CatalogueHash::of($stored['step_definitions']) !== CatalogueHash::of($proposal['steps']));
        $state = $stored['state'];
        if ($proposal !== null) {
            $state = (new RebaseProcessState)->for($state, $stored['step_definitions'], $proposal['steps']);
            $state = (new ProcessStateMachine)->withPrerequisites($state, $proposal['steps'], (new ProcessPrerequisites)->for($proposals, $input->processes));
        }

        return ['schema_version' => 'bureaucracy.process.1', 'id' => $stored['id'], 'definition_id' => $stored['definition_id'],
            'jurisdiction' => $input->jurisdiction, 'version' => $stored['version'], 'occurrence_key' => $stored['occurrence_key'], 'state' => $state,
            'guidance_state' => $proposal === null ? 'history_only' : ($review ? 'review_required' : 'current'),
            'review_token' => $proposal['review_token'] ?? null, 'bind_occurrence' => $binding ? $proposal['occurrence_key'] : null,
            'current_steps' => $proposal['steps'] ?? [],
            'progress' => (new ProgressSummary)->for($proposal === null ? [] : [['id' => $stored['id'], 'state' => $state]])];
    }
}
