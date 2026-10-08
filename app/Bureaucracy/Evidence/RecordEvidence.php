<?php

namespace App\Bureaucracy\Evidence;

use App\Bureaucracy\Facts\CalendarDate;
use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonCommandScope;
use App\Models\BureaucracyEvidenceItem;
use App\Models\BureaucracyOutboxEvent;
use App\Models\BureaucracyPerson;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class RecordEvidence
{
    public function __construct(private PersonCommandScope $scope) {}

    public function execute(User $actor, BureaucracyPerson $person, string $id, array $details, int $expectedVersion, string $requestId, string $status = 'active'): EvidenceWriteReceipt
    {
        if (! Str::isUuid($id) || ! Str::isUuid($requestId) || $expectedVersion < 0 || ! in_array($status, ['active', 'archived'], true)) {
            throw ValidationException::withMessages(['evidence' => 'Use an evidence identifier, request identifier and current version.']);
        }
        $refs = $details['requirement_refs'] ?? [];
        if (array_diff(array_keys($details), ['label', 'kind', 'reported_available', 'expires_on', 'requirement_refs']) !== []
            || ! is_array($refs) || ! array_is_list($refs) || count($refs) > 50
            || array_filter($refs, fn ($ref) => ! is_string($ref) || ! preg_match('/^[a-z0-9][a-zA-Z0-9._-]{0,199}$/D', $ref)) !== []
            || ! is_string($details['label'] ?? null) || trim($details['label']) === '' || mb_strlen($details['label']) > 160
            || ! is_string($details['kind'] ?? null) || ! preg_match('/^[a-z][a-z0-9._-]{0,99}$/D', $details['kind'])
            || ! is_bool($details['reported_available'] ?? null)
            || (isset($details['expires_on']) && CalendarDate::parse($details['expires_on']) === null)) {
            throw ValidationException::withMessages(['details' => 'Record a short label, document kind, availability, an exact optional expiry date and the requirement identifiers it answers. Do not include document contents.']);
        }
        $id = strtolower($id);
        $requestId = strtolower($requestId);
        $details = [...$details, 'label' => trim($details['label']), 'expires_on' => $details['expires_on'] ?? null];
        if (array_key_exists('requirement_refs', $details)) {
            $details['requirement_refs'] = array_values(array_unique($refs));
        }

        return $this->scope->run($actor, $person, AccessScope::ManageEvidence, function ($person) use ($actor, $id, $requestId, $details, $expectedVersion, $status): EvidenceWriteReceipt {
            $item = BureaucracyEvidenceItem::query()->whereKey($id)->lockForUpdate()->first();
            if ($item !== null && $item->person_id !== $person->id) {
                throw new AuthorizationException;
            }
            $fingerprint = ProcessingConsentStore::digest([$details, $expectedVersion, $status]);
            $replay = $item?->events()->where('request_id', $requestId)->first();
            if ($replay !== null) {
                if ($replay->actor_id !== $actor->id || $replay->request_fingerprint !== $fingerprint) {
                    throw new ConflictHttpException('That request was used for a different document update.');
                }

                return new EvidenceWriteReceipt($item->id, $replay->evidence_version, $replay->type === 'archived' ? 'archived' : 'active');
            }
            if (($item?->version ?? 0) !== $expectedVersion) {
                throw new ConflictHttpException('This document record changed. Reload before editing.');
            }
            $item ??= new BureaucracyEvidenceItem(['id' => $id, 'person_id' => $person->id]);
            $item->fill(['version' => $expectedVersion + 1, 'status' => $status, 'details' => $details])->save();
            $item->events()->create(['actor_id' => $actor->id, 'request_id' => $requestId, 'request_fingerprint' => $fingerprint,
                'evidence_version' => $item->version, 'type' => $status === 'archived' ? 'archived' : 'reported',
                'payload' => [...$details, 'provenance' => 'user_report'], 'recorded_at' => now()->utc()]);
            $person->increment('record_version');
            BureaucracyOutboxEvent::query()->create(['event_type' => 'evidence.changed', 'aggregate_type' => 'person',
                'aggregate_id' => $person->id, 'aggregate_version' => $person->record_version, 'dedupe_key' => 'evidence.changed:'.$id.':'.$item->version,
                'payload' => ['person_id' => $person->id, 'evidence_id' => $id], 'available_at' => now()->utc()]);

            return new EvidenceWriteReceipt($item->id, $item->version, $item->status);
        });
    }
}
