<?php

namespace App\Bureaucracy\Processes;

use App\Bureaucracy\Assessment\PrepareAssessmentInput;
use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonCommandScope;
use App\Models\BureaucracyOutboxEvent;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyProcess;
use App\Models\User;

final class ReconcileProcesses
{
    public function __construct(private PersonCommandScope $scope, private PrepareAssessmentInput $inputs, private DiscoverProcesses $discover, private ProcessStateMachine $states) {}

    public function execute(User $actor, BureaucracyPerson $person, string $jurisdiction, array $keepHistorySeparate = [], ?array $selectedOccurrences = null): array
    {
        return $this->scope->run($actor, $person, AccessScope::ManageProcess, function ($person, $case) use ($actor, $jurisdiction, $keepHistorySeparate, $selectedOccurrences): array {
            $input = $this->inputs->for($actor, $person, $jurisdiction);
            $result = [];
            foreach ($this->discover->for($input) as $proposal) {
                if ($selectedOccurrences !== null && ! in_array($proposal['occurrence_key'], $selectedOccurrences, true)) {
                    continue;
                }
                $existing = BureaucracyProcess::query()->where('case_id', $case->id)->where('occurrence_key', $proposal['occurrence_key'])->first();
                if ($existing === null && $proposal['occurrence_fact'] !== null && ! str_starts_with($proposal['context_id'], 'unbound:')) {
                    $provisional = BureaucracyProcess::query()->where('case_id', $case->id)->where('jurisdiction', $jurisdiction)
                        ->where('definition_id', $proposal['definition_id'])->where('context_id', 'unbound:'.$proposal['occurrence_fact'])->first();
                    if ($provisional !== null && ! in_array($provisional->id, $keepHistorySeparate, true)) {
                        $result[] = $provisional;

                        continue; // The person must confirm continuity or explicitly keep this history separate.
                    }
                }
                $process = BureaucracyProcess::query()->firstOrCreate(['case_id' => $case->id, 'definition_id' => $proposal['definition_id'],
                    'jurisdiction' => $jurisdiction, 'occurrence_key' => $proposal['occurrence_key']], [
                        'topic' => $proposal['topic'], 'context_id' => $proposal['context_id'], 'catalogue_hash' => $proposal['catalogue_hash'],
                        'step_definitions' => $proposal['steps'], 'state' => $this->states->initial($proposal['steps']),
                    ]);
                if ($process->wasRecentlyCreated) {
                    $person->increment('record_version');
                    BureaucracyOutboxEvent::query()->create(['event_type' => 'process.discovered', 'aggregate_type' => 'process',
                        'aggregate_id' => $process->id, 'aggregate_version' => 1, 'dedupe_key' => 'process.discovered:'.$process->id,
                        'payload' => ['person_id' => $person->id], 'available_at' => now()->utc()]);
                }
                $result[] = $process->fresh();
            }

            return $result;
        });
    }
}
