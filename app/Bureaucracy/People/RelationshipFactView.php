<?php

namespace App\Bureaucracy\People;

use App\Bureaucracy\Facts\CalendarDate;
use App\Bureaucracy\Facts\ConfirmedFactView;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyRelationship;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;
use DomainException;

final class RelationshipFactView
{
    public function __construct(private PersonAccess $access, private ConfirmedFactView $facts) {}

    public function forApplicant(User $actor, BureaucracyPerson $applicant, int $relationshipId, string $onDate): array
    {
        $this->access->authorize($actor, $applicant, AccessScope::ViewPlan);
        if (CalendarDate::parse($onDate) === null) {
            throw new DomainException('An exact evaluation date is required.');
        }
        $link = BureaucracyRelationship::query()->whereKey($relationshipId)->where('person_id', $applicant->id)->first();
        if ($link === null || $link->revoked_at !== null || $link->type !== 'sponsor'
            || $link->confirmed_at === null
            || ($link->effective_from === null && $onDate < $link->confirmed_at->copy()->setTimezone(config('app.timezone'))->toDateString())
            || ($link->effective_from !== null && $link->effective_from->toDateString() > $onDate)
            || ($link->effective_until !== null && $link->effective_until->toDateString() <= $onDate)) {
            return $this->result('relationship_unavailable', [], [$applicant->id, $relationshipId, false]);
        }
        $related = BureaucracyPerson::query()->find($link->related_person_id);
        if ($related === null || ! $this->access->allows($actor, $related, AccessScope::ViewFacts) || $related->dossier === null) {
            return $this->result('access_unavailable', [], [$applicant->id, $relationshipId, false, $related?->record_version]);
        }
        $facts = $this->facts->forCase($related->dossier, $onDate);
        $values = [];
        if (isset($facts['values']['current_residence_title'])) {
            $values['sponsor_current_title'] = $facts['values']['current_residence_title'];
        }
        if (($facts['values']['citizenship_group'] ?? null) === 'non_eu') {
            $values['sponsor'] = 'non_eu';
        }

        return $this->result('available', $values, [$actor->id, $applicant->id, $link->id, $link->updated_at->toIso8601String(), $related->record_version, $facts]);
    }

    private function result(string $status, array $values, array $dependencies): array
    {
        return ['status' => $status, 'values' => $values, 'dependency_token' => ProcessingConsentStore::digest(['status' => $status, 'dependencies' => $dependencies])];
    }
}
