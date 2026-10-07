<?php

namespace App\Bureaucracy\Processes;

use App\Bureaucracy\Assessment\PrepareAssessmentInput;
use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonCommandScope;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyProcessEvent;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class StartProcess
{
    public function __construct(private PersonCommandScope $scope, private PrepareAssessmentInput $inputs,
        private DiscoverProcesses $discover, private ReconcileProcesses $processes) {}

    public function execute(User $actor, BureaucracyPerson $person, string $jurisdiction, string $occurrence, string $reviewToken, string $requestId): array
    {
        if (! Str::isUuid($requestId) || ! preg_match('/^[a-f0-9]{64}$/D', $occurrence) || ! preg_match('/^[a-f0-9]{64}$/D', $reviewToken)
            || ! array_key_exists($jurisdiction, config('bureaucracy_catalogue.jurisdictions'))) {
            throw ValidationException::withMessages(['process' => 'Select a current process proposal.']);
        }
        $requestId = strtolower($requestId);

        return $this->scope->run($actor, $person, AccessScope::ManageProcess, function ($person) use ($actor, $jurisdiction, $occurrence, $reviewToken, $requestId): array {
            $input = $this->inputs->for($actor, $person, $jurisdiction);
            $fingerprint = ProcessingConsentStore::digest(['start', $person->id, $jurisdiction, $occurrence, $reviewToken]);
            $replay = BureaucracyProcessEvent::query()->where('actor_id', $actor->id)->where('request_id', $requestId)->first();
            if ($replay !== null) {
                if ($replay->type !== 'process_started' || $replay->request_fingerprint !== $fingerprint) {
                    throw new ConflictHttpException('This request identifier was already used for a different process.');
                }

                return $this->receipt($replay);
            }
            $proposal = collect($this->discover->for($input))->firstWhere('occurrence_key', $occurrence);
            if ($proposal === null || ! hash_equals($proposal['review_token'], $reviewToken)) {
                throw new ConflictHttpException('The proposed process changed. Review the current plan before starting it.');
            }
            $process = $this->processes->execute($actor, $person, $jurisdiction, selectedOccurrences: [$occurrence])[0] ?? null;
            if ($process === null || $process->occurrence_key !== $occurrence) {
                throw new ConflictHttpException('Existing preparation may belong to this process. Review continuity before creating another one.');
            }
            // Opening a saved process does not report preparation, submission or completion.
            $event = $process->events()->create(['actor_id' => $actor->id, 'request_id' => $requestId, 'request_fingerprint' => $fingerprint,
                'type' => 'process_started', 'payload' => ['provenance' => 'explicit_user_command'],
                'process_version' => $process->version, 'recorded_at' => now()->utc()]);

            return $this->receipt($event);
        });
    }

    private function receipt(BureaucracyProcessEvent $event): array
    {
        return ['process_id' => $event->process_id, 'version' => $event->process_version, 'event_id' => $event->id];
    }
}
