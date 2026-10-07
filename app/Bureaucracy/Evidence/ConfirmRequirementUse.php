<?php

namespace App\Bureaucracy\Evidence;

use App\Bureaucracy\Assessment\AssessmentFacts;
use App\Bureaucracy\Assessment\PrepareAssessmentInput;
use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonCommandScope;
use App\Bureaucracy\Processes\DiscoverProcesses;
use App\Models\BureaucracyEvidenceItem;
use App\Models\BureaucracyOutboxEvent;
use App\Models\BureaucracyProcess;
use App\Models\BureaucracyRequirementUse;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ConfirmRequirementUse
{
    public function __construct(private PersonCommandScope $scope, private PrepareAssessmentInput $inputs,
        private DiscoverProcesses $discover, private EvidenceRequirements $requirements, private EvidenceAccess $evidenceAccess) {}

    public function execute(User $actor, BureaucracyProcess $process, string $requirementId, string $evidenceId, int $expectedProcessVersion,
        int $evidenceVersion, string $requirementHash, string $requestId): BureaucracyRequirementUse
    {
        if (! Str::isUuid($requestId) || ! Str::isUuid($evidenceId) || strlen($requirementId) > 200) {
            throw ValidationException::withMessages(['evidence' => 'Use current requirement and document identifiers.']);
        }
        $requestId = strtolower($requestId);
        $evidenceId = strtolower($evidenceId);

        return $this->scope->run($actor, $process->dossier->person, AccessScope::ManageEvidence, function ($person, $case) use ($actor, $process, $requirementId, $evidenceId, $expectedProcessVersion, $evidenceVersion, $requirementHash, $requestId): BureaucracyRequirementUse {
            $current = BureaucracyProcess::query()->whereKey($process->id)->where('case_id', $case->id)->lock('for no key update')->firstOrFail();
            $input = $this->inputs->for($actor, $person, $current->jurisdiction);
            $fingerprint = ProcessingConsentStore::digest([$requirementId, $evidenceId, $expectedProcessVersion, $evidenceVersion, $requirementHash]);
            $replay = BureaucracyRequirementUse::query()->where('process_id', $current->id)->where('request_id', $requestId)->first();
            if ($replay !== null) {
                if ($replay->actor_id !== $actor->id || $replay->request_fingerprint !== $fingerprint) {
                    throw new ConflictHttpException('That request was already used for a different confirmation.');
                }

                return $replay;
            }
            $item = BureaucracyEvidenceItem::query()->whereKey($evidenceId)->lockForUpdate()->firstOrFail();
            $share = $item->person_id === $person->id ? null : $this->evidenceAccess->shareFor($item, $current->id, $requirementId, $requirementHash);
            if ($item->person_id !== $person->id && $share === null) {
                throw new AuthorizationException;
            }
            if ($current->version !== $expectedProcessVersion || $item->version !== $evidenceVersion) {
                throw new ConflictHttpException('The process or document changed. Check the latest version.');
            }
            $proposals = $this->discover->for($input);
            $proposal = collect($proposals)->firstWhere('occurrence_key', $current->occurrence_key);
            $requirements = $this->requirements->for($proposal['variants'] ?? [], (new AssessmentFacts)->combine($input->facts, $input->relationships), $input->at);
            $requirement = collect($requirements)->firstWhere('id', $requirementId);
            if ($requirement === null || ! hash_equals($requirement['semantic_hash'], $requirementHash)) {
                throw new ConflictHttpException('This document requirement changed or is no longer available.');
            }
            if ($requirement['applicability'] !== 'required' || ! $this->evidenceAccess->usable($item, $input->at->toDateString(), $requirement['evidence_kind'])) {
                throw ValidationException::withMessages(['evidence' => 'Clarify this requirement and check that the document is available and current first.']);
            }
            BureaucracyRequirementUse::query()->where('process_id', $current->id)->where('requirement_id', $requirementId)
                ->whereNull('superseded_at')->update(['superseded_at' => now()->utc()]);
            $version = $current->version + 1;
            $use = BureaucracyRequirementUse::query()->create(['process_id' => $current->id, 'requirement_id' => $requirementId,
                'requirement_hash' => $requirementHash, 'evidence_id' => $item->id, 'evidence_version' => $item->version, 'share_id' => $share?->id,
                'actor_id' => $actor->id, 'request_id' => $requestId, 'request_fingerprint' => $fingerprint,
                'process_version' => $version, 'confirmed_at' => now()->utc()]);
            $current->update(['version' => $version]);
            $person->increment('record_version');
            BureaucracyOutboxEvent::query()->create(['event_type' => 'process.changed', 'aggregate_type' => 'process', 'aggregate_id' => $current->id,
                'aggregate_version' => $version, 'dedupe_key' => 'process.changed:'.$current->id.':'.$version,
                'payload' => ['person_id' => $person->id], 'available_at' => now()->utc()]);

            return $use->fresh();
        });
    }
}
