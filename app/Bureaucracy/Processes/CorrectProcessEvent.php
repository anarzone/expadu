<?php

namespace App\Bureaucracy\Processes;

use App\Models\BureaucracyProcess;
use App\Models\BureaucracyProcessEvent;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CorrectProcessEvent
{
    public function __construct(private ProcessMutation $mutation, private ProcessEventPayload $payloads) {}

    /** Correct report metadata. Undoing work uses an explicit reopening event, not rewritten history. */
    public function execute(User $actor, BureaucracyProcess $process, int $eventId, array $payload, int $expectedVersion, string $requestId): BureaucracyProcessEvent
    {
        return $this->mutation->execute($actor, $process, $expectedVersion, $requestId, ['correct_report', $eventId, $payload], function ($current) use ($eventId, $payload): array {
            $original = $current->events()->whereKey($eventId)->firstOrFail();
            if ($current->events()->where('corrects_event_id', $original->id)->exists()) {
                throw new ConflictHttpException('That report already has a correction. Review its latest version.');
            }
            $normalized = $this->payloads->validate($original->type, $payload);
            foreach (['step_id', 'appointment_id'] as $identity) {
                if (($original->payload[$identity] ?? null) !== ($normalized[$identity] ?? null)) {
                    throw ValidationException::withMessages(['payload.'.$identity => 'Corrections keep the same item. Reopen work or cancel the original appointment to undo it.']);
                }
            }

            return ['event' => ['type' => $original->type, 'payload' => [...$normalized, 'provenance' => 'user_report'], 'corrects_event_id' => $original->id]];
        });
    }
}
