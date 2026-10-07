<?php

namespace App\Bureaucracy\People;

use App\Bureaucracy\Facts\CalendarDate;
use App\Models\BureaucracyOutboxEvent;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyRelationship;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class RecordRelationship
{
    public function __construct(private PersonAccess $access) {}

    public function execute(User $actor, BureaucracyPerson $applicant, BureaucracyPerson $relatedPerson, string $type, ?string $effectiveFrom, int $expectedRevision): BureaucracyRelationship
    {
        if (! in_array($type, ['spouse', 'sponsor', 'parent', 'child'], true) || ($effectiveFrom !== null && CalendarDate::historical($effectiveFrom) === null)) {
            throw ValidationException::withMessages(['relationship' => 'Use a supported relationship and a known historical date, or leave the date unknown.']);
        }

        return DB::transaction(function () use ($actor, $applicant, $relatedPerson, $type, $effectiveFrom, $expectedRevision): BureaucracyRelationship {
            User::query()->whereKey($actor->id)->lock('for no key update')->firstOrFail();
            $people = BureaucracyPerson::query()->whereKey([$applicant->id, $relatedPerson->id])->orderBy('id')->lock('for no key update')->get()->keyBy('id');
            $one = $people->get($applicant->id);
            $two = $people->get($relatedPerson->id);
            if ($one === null || $two === null || $one->id === $two->id) {
                throw new AuthorizationException;
            }
            $this->access->authorize($actor, $one, AccessScope::EditFacts);
            $this->access->authorize($actor, $two, AccessScope::ViewFacts);
            $workspace = $one->workspaces()->whereHas('people', fn ($query) => $query->whereKey($two->id))->orderBy('id')->first();
            if ($workspace === null) {
                throw new AuthorizationException;
            }
            $existing = BureaucracyRelationship::query()->where('person_id', $one->id)->where('related_person_id', $two->id)
                ->where('type', $type)->whereNull('revoked_at')->lockForUpdate()->first();
            if ($existing !== null && $existing->effective_from?->toDateString() === $effectiveFrom) {
                return $existing;
            }
            if ($one->record_version !== $expectedRevision) {
                throw new ConflictHttpException('This person’s record changed. Refresh before changing the relationship.');
            }
            $existing?->update(['revoked_at' => now()->utc()]);
            $link = BureaucracyRelationship::query()->create([
                'workspace_id' => $workspace->id, 'person_id' => $one->id, 'related_person_id' => $two->id,
                'type' => $type, 'effective_from' => $effectiveFrom, 'confirmed_by' => $actor->id,
                'source' => 'applicant_report', 'confirmed_at' => now()->utc(),
            ]);
            $this->changed($one, $link);

            return $link->fresh();
        });
    }

    public function revoke(User $actor, BureaucracyRelationship $relationship): void
    {
        DB::transaction(function () use ($actor, $relationship): void {
            User::query()->whereKey($actor->id)->lock('for no key update')->firstOrFail();
            $link = BureaucracyRelationship::query()->findOrFail($relationship->id);
            $person = BureaucracyPerson::query()->whereKey($link->person_id)->lock('for no key update')->firstOrFail();
            $this->access->authorize($actor, $person, AccessScope::EditFacts);
            $link = BureaucracyRelationship::query()->whereKey($link->id)->lockForUpdate()->firstOrFail();
            if ($link->revoked_at === null) {
                $link->update(['revoked_at' => now()->utc()]);
                $this->changed($person, $link);
            }
        });
    }

    private function changed(BureaucracyPerson $person, BureaucracyRelationship $link): void
    {
        $person->increment('record_version');
        BureaucracyOutboxEvent::query()->create([
            'event_type' => 'relationship.changed', 'aggregate_type' => 'person', 'aggregate_id' => $person->id,
            'aggregate_version' => $person->record_version, 'dedupe_key' => "relationship.changed:{$person->id}:{$person->record_version}",
            'payload' => ['relationship_id' => $link->id], 'available_at' => now()->utc(),
        ]);
    }
}
