<?php

namespace App\Bureaucracy\Evidence;

use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Bureaucracy\People\PersonCommandScope;
use App\Models\BureaucracyOutboxEvent;
use App\Models\BureaucracyProcess;
use App\Models\BureaucracyRequirementUse;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class WithdrawRequirementUse
{
    public function __construct(private PersonCommandScope $scope, private PersonAccess $access) {}

    public function execute(User $actor, BureaucracyProcess $process, string $requirementId, int $expectedVersion, string $requestId): BureaucracyRequirementUse
    {
        if (! Str::isUuid($requestId)) {
            throw ValidationException::withMessages(['request_id' => 'Use a request identifier.']);
        }
        $requestId = strtolower($requestId);

        return $this->scope->run($actor, $process->dossier->person, AccessScope::ManageEvidence, function ($person, $case) use ($actor, $process, $requirementId, $expectedVersion, $requestId): BureaucracyRequirementUse {
            $this->access->authorize($actor, $person, AccessScope::ViewPlan);
            $current = BureaucracyProcess::query()->whereKey($process->id)->where('case_id', $case->id)->lock('for no key update')->firstOrFail();
            $fingerprint = ProcessingConsentStore::digest(['withdraw', $requirementId, $expectedVersion]);
            $replay = BureaucracyRequirementUse::query()->where('process_id', $current->id)->where('request_id', $requestId)->first();
            if ($replay !== null) {
                if ($replay->actor_id !== $actor->id || $replay->request_fingerprint !== $fingerprint) {
                    throw new ConflictHttpException('That request was already used for a different update.');
                }

                return $replay;
            }
            if ($current->version !== $expectedVersion) {
                throw new ConflictHttpException('The process changed. Reload its document list.');
            }
            $use = BureaucracyRequirementUse::query()->where('process_id', $current->id)->where('requirement_id', $requirementId)->whereNull('superseded_at')->firstOrFail();
            $use->update(['superseded_at' => now()->utc()]);
            $version = $current->version + 1;
            $receipt = BureaucracyRequirementUse::query()->create(['process_id' => $current->id, 'requirement_id' => $requirementId,
                'requirement_hash' => $use->requirement_hash, 'status' => 'withdrawn', 'evidence_version' => 0,
                'actor_id' => $actor->id, 'request_id' => $requestId, 'request_fingerprint' => $fingerprint, 'process_version' => $version, 'confirmed_at' => now()->utc()]);
            $current->update(['version' => $version]);
            $person->increment('record_version');
            BureaucracyOutboxEvent::query()->create(['event_type' => 'process.changed', 'aggregate_type' => 'process', 'aggregate_id' => $current->id,
                'aggregate_version' => $version, 'dedupe_key' => 'process.changed:'.$current->id.':'.$version,
                'payload' => ['person_id' => $person->id], 'available_at' => now()->utc()]);

            return $receipt;
        });
    }
}
