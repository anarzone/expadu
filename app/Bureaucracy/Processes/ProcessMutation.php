<?php

namespace App\Bureaucracy\Processes;

use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Bureaucracy\People\PersonCommandScope;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyOutboxEvent;
use App\Models\BureaucracyProcess;
use App\Models\BureaucracyProcessEvent;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** One locked, idempotent append and projection update, including the invalidation event. */
final class ProcessMutation
{
    public function __construct(private PersonCommandScope $scope, private PersonAccess $access) {}

    public function execute(User $actor, BureaucracyProcess $process, int $expectedVersion, string $requestId, array $command, callable $apply): BureaucracyProcessEvent
    {
        if (! Str::isUuid($requestId)) {
            throw ValidationException::withMessages(['request_id' => 'Use a request identifier for this event.']);
        }
        $requestId = strtolower($requestId);
        $person = BureaucracyCase::query()->findOrFail($process->case_id)->person;

        return $this->scope->run($actor, $person, AccessScope::ManageProcess, function ($person, $case) use ($actor, $process, $expectedVersion, $requestId, $command, $apply): BureaucracyProcessEvent {
            $this->access->authorize($actor, $person, AccessScope::ViewPlan);
            $current = BureaucracyProcess::query()->whereKey($process->id)->where('case_id', $case->id)->lockForUpdate()->firstOrFail();
            $fingerprint = ProcessingConsentStore::digest([$command, $expectedVersion]);
            $replay = $current->events()->where('request_id', $requestId)->first();
            if ($replay !== null) {
                if ($replay->actor_id !== $actor->id || $replay->request_fingerprint !== $fingerprint) {
                    throw new ConflictHttpException('This request was already used for a different report.');
                }

                return $replay;
            }
            if ($current->version !== $expectedVersion) {
                throw new ConflictHttpException('This process changed. Reload before reporting progress.');
            }
            $change = $apply($current, $person);
            $version = $current->version + 1;
            $event = $current->events()->create([...$change['event'], 'actor_id' => $actor->id, 'request_id' => $requestId,
                'request_fingerprint' => $fingerprint, 'process_version' => $version, 'recorded_at' => now()->utc()]);
            $current->update([...($change['updates'] ?? []), 'version' => $version]);
            $person->increment('record_version');
            BureaucracyOutboxEvent::query()->create(['event_type' => 'process.changed', 'aggregate_type' => 'process',
                'aggregate_id' => $current->id, 'aggregate_version' => $version,
                'dedupe_key' => 'process.changed:'.$current->id.':'.$version,
                'payload' => ['person_id' => $person->id], 'available_at' => now()->utc()]);

            return $event->fresh();
        });
    }
}
