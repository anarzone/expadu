<?php

namespace App\Bureaucracy\Evidence;

use App\Bureaucracy\Assessment\AssessmentFacts;
use App\Bureaucracy\Assessment\PrepareAssessmentInput;
use App\Bureaucracy\Facts\CalendarDate;
use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Bureaucracy\People\PersonCommandScope;
use App\Bureaucracy\Processes\DiscoverProcesses;
use App\Models\BureaucracyEvidenceItem;
use App\Models\BureaucracyEvidenceShare;
use App\Models\BureaucracyOutboxEvent;
use App\Models\BureaucracyProcess;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ShareEvidence
{
    public const NoticeVersion = '2026-09-08.single-requirement.1';

    public function __construct(private PersonCommandScope $scope, private PersonAccess $access, private PrepareAssessmentInput $inputs) {}

    public function execute(User $actor, BureaucracyEvidenceItem $evidence, BureaucracyProcess $process, string $requirementId,
        int $evidenceVersion, string $requirementHash, string $expiresOn, string $requestId): BureaucracyEvidenceShare
    {
        if (! Str::isUuid($requestId) || CalendarDate::parse($expiresOn) === null) {
            throw ValidationException::withMessages(['share' => 'Use a request identifier and an exact sharing end date.']);
        }
        // Never acquire another person's dossier lock while holding the owner's write lock.
        // This snapshot binds the permission to exact semantics; every actual use rechecks current guidance.
        $input = $this->inputs->for($actor, $process->dossier->person, $process->jurisdiction);
        $proposal = collect((new DiscoverProcesses)->for($input))->firstWhere('occurrence_key', $process->occurrence_key);
        $requirement = collect((new EvidenceRequirements)->for($proposal['variants'] ?? [], (new AssessmentFacts)->combine($input->facts, $input->relationships), $input->at))->firstWhere('id', $requirementId);
        if ($requirement === null || $requirement['applicability'] !== 'required' || ! hash_equals($requirement['semantic_hash'], $requirementHash)) {
            throw new ConflictHttpException('Review the current document requirement before sharing.');
        }
        $expires = CarbonImmutable::createFromFormat('!Y-m-d', $expiresOn, $input->at->getTimezone())->endOfDay();
        if ($expires->lessThanOrEqualTo($input->at) || $expires->greaterThan($input->at->addDays(365)->endOfDay())) {
            throw ValidationException::withMessages(['expires_on' => 'Choose a future sharing end date within one year.']);
        }
        $requestId = strtolower($requestId);

        return $this->scope->run($actor, $evidence->person, AccessScope::ManageEvidence, function ($owner) use ($actor, $evidence, $process, $requirementId, $evidenceVersion, $requirementHash, $expires, $requestId): BureaucracyEvidenceShare {
            if (! $this->access->canManage($actor, $owner)) {
                throw new AuthorizationException;
            }
            $this->access->authorize($actor, $process->dossier->person, AccessScope::ViewPlan);
            $item = BureaucracyEvidenceItem::query()->whereKey($evidence->id)->where('person_id', $owner->id)->lockForUpdate()->firstOrFail();
            $fingerprint = ProcessingConsentStore::digest([$process->id, $requirementId, $evidenceVersion, $requirementHash, $expires->toIso8601String(), self::NoticeVersion]);
            $replay = BureaucracyEvidenceShare::query()->where('evidence_id', $item->id)->where('request_id', $requestId)->first();
            if ($replay !== null) {
                if ($replay->grantor_id !== $actor->id || $replay->request_fingerprint !== $fingerprint) {
                    throw new ConflictHttpException('That request was already used for different sharing.');
                }

                return $replay;
            }
            if ($item->version !== $evidenceVersion || $item->status !== 'active') {
                throw new ConflictHttpException('This document changed. Review the latest version before sharing.');
            }
            $share = BureaucracyEvidenceShare::query()->create(['evidence_id' => $item->id, 'evidence_version' => $item->version,
                'process_id' => $process->id, 'requirement_id' => $requirementId, 'requirement_hash' => $requirementHash,
                'notice_version' => self::NoticeVersion, 'grantor_id' => $actor->id, 'confirmed_at' => now()->utc(), 'expires_at' => $expires->utc(),
                'request_id' => $requestId, 'request_fingerprint' => $fingerprint]);
            $owner->increment('record_version');
            $this->changed($owner->id, $owner->record_version, $share, 'granted');

            return $share;
        });
    }

    public function revoke(User $actor, BureaucracyEvidenceShare $share): void
    {
        $evidence = BureaucracyEvidenceItem::query()->findOrFail($share->evidence_id);
        $this->scope->run($actor, $evidence->person, AccessScope::ManageEvidence, function ($owner) use ($actor, $share, $evidence): void {
            if (! $this->access->canManage($actor, $owner)) {
                throw new AuthorizationException;
            }
            BureaucracyEvidenceItem::query()->whereKey($evidence->id)->lockForUpdate()->firstOrFail();
            $current = BureaucracyEvidenceShare::query()->whereKey($share->id)->where('evidence_id', $evidence->id)->lockForUpdate()->firstOrFail();
            if ($current->revoked_at === null) {
                $current->update(['revoked_at' => now()->utc()]);
                $owner->increment('record_version');
                $this->changed($owner->id, $owner->record_version, $current, 'revoked');
            }
        });
    }

    private function changed(int $personId, int $version, BureaucracyEvidenceShare $share, string $operation): void
    {
        BureaucracyOutboxEvent::query()->create(['event_type' => 'evidence.sharing_changed', 'aggregate_type' => 'person', 'aggregate_id' => $personId,
            'aggregate_version' => $version, 'dedupe_key' => 'evidence.share.'.$operation.':'.$share->id,
            'payload' => ['person_id' => $personId, 'process_id' => $share->process_id], 'available_at' => now()->utc()]);
    }
}
