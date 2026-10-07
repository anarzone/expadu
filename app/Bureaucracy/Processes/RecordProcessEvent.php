<?php

namespace App\Bureaucracy\Processes;

use App\Bureaucracy\Assessment\PrepareAssessmentInput;
use App\Bureaucracy\Catalogue\CatalogueHash;
use App\Models\BureaucracyProcess;
use App\Models\BureaucracyProcessEvent;
use App\Models\User;
use DomainException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class RecordProcessEvent
{
    public function __construct(private ProcessMutation $mutation, private PrepareAssessmentInput $inputs,
        private DiscoverProcesses $discover, private ProcessStateMachine $machine, private ProcessEventPayload $payloads) {}

    public function execute(User $actor, BureaucracyProcess $process, string $type, array $payload, int $expectedVersion, string $requestId, ?string $reviewToken = null): BureaucracyProcessEvent
    {
        $payload = $this->payloads->validate($type, $payload);

        return $this->mutation->execute($actor, $process, $expectedVersion, $requestId, [$type, $payload, $reviewToken], function ($current, $person) use ($actor, $type, $payload, $reviewToken): array {
            $input = $this->inputs->for($actor, $person, $current->jurisdiction);
            $proposals = $this->discover->for($input);
            $proposal = array_column($proposals, null, 'occurrence_key')[$current->occurrence_key] ?? null;
            $cancelling = in_array($type, ['appointment_cancelled', 'cancellation_reported'], true);
            if (! $cancelling && ($proposal === null || $proposal['catalogue_hash'] !== $current->catalogue_hash
                || CatalogueHash::of($proposal['steps']) !== CatalogueHash::of($current->step_definitions)
                || ($reviewToken !== null && ! hash_equals($proposal['review_token'], $reviewToken)))) {
                throw new ConflictHttpException('This guidance or its relevant steps changed. Review the current plan before recording progress.');
            }
            if (isset($payload['step_id']) && ! in_array($payload['step_id'], array_column($proposal['steps'], 'id'), true)) {
                throw ValidationException::withMessages(['payload.step_id' => 'That step is not currently actionable in this process.']);
            }
            if ($type === 'appointment_cancelled') {
                $events = (new ProcessHistory)->active($current->events()->orderBy('id')->get()->map(fn ($event) => [
                    'id' => $event->id, 'type' => $event->type, 'payload' => $event->payload, 'corrects_event_id' => $event->corrects_event_id,
                ])->all());
                $appointment = collect($events)->reverse()->first(fn ($event) => in_array($event['type'], ['appointment_recorded', 'appointment_cancelled'], true)
                    && ($event['payload']['appointment_id'] ?? null) === $payload['appointment_id']);
                if (($appointment['type'] ?? null) !== 'appointment_recorded') {
                    throw ValidationException::withMessages(['payload.appointment_id' => 'Choose a recorded appointment in this process.']);
                }
            }
            try {
                $state = $this->machine->apply($current->state, $type, $payload, $proposal['steps'] ?? $current->step_definitions, (new ProcessPrerequisites)->for($proposals, $input->processes));
            } catch (DomainException $error) {
                throw ValidationException::withMessages(['event' => $error->getMessage()]);
            }

            return ['event' => ['type' => $type, 'payload' => [...$payload, 'provenance' => 'user_report']], 'updates' => ['state' => $state]];
        });
    }
}
