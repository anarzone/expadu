<?php

namespace App\Bureaucracy\ReadModel;

use App\Bureaucracy\Assessment\AssessmentInput;
use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Models\BureaucracyAccessGrant;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyEvidenceItem;
use App\Models\BureaucracyEvidenceShare;
use App\Models\BureaucracyGuardianAuthority;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyQuestionSession;
use App\Models\BureaucracyRelationship;
use App\Models\User;
use Carbon\CarbonImmutable;

final class NextAssessmentBoundary
{
    public function __construct(private PersonAccess $access) {}

    public function for(User $actor, BureaucracyPerson $person, AssessmentInput $input, array $timeline): string
    {
        $boundaries = [$input->at->addDay()->startOfDay()];
        $add = function ($value) use (&$boundaries, $input): void {
            if ($value !== null) {
                $instant = CarbonImmutable::parse($value);
                if ($instant->greaterThan($input->at)) {
                    $boundaries[] = $instant;
                }
            }
        };
        $this->factBoundaries($person->dossier->id, $add);
        foreach (BureaucracyRelationship::query()->where('person_id', $person->id)->where('type', 'sponsor')->whereNull('revoked_at')->get() as $link) {
            $related = BureaucracyPerson::query()->find($link->related_person_id);
            if ($related !== null && $this->access->allows($actor, $related, AccessScope::ViewFacts) && $related->dossier !== null) {
                $this->factBoundaries($related->dossier->id, $add, ['current_residence_title', 'citizenship_group']);
            }
        }
        foreach (BureaucracyAccessGrant::query()->where('grantee_user_id', $actor->id)->whereNull('revoked_at')
            ->where('expires_at', '>', $input->at->utc())->get() as $grant) {
            $add($grant->expires_at);
        }
        foreach (BureaucracyGuardianAuthority::query()->where('guardian_user_id', $actor->id)->whereNull('revoked_at')
            ->where('status', 'approved')->where('expires_at', '>', $input->at->utc())->get() as $authority) {
            $add($authority->expires_at);
        }
        if ($this->access->allows($actor, $person, AccessScope::ManageEvidence)) {
            $shares = BureaucracyEvidenceShare::query()->whereIn('process_id', array_column($input->processes, 'id'))
                ->whereNull('revoked_at')->where('expires_at', '>', $input->at->utc())->get();
            foreach ($shares as $share) {
                $add($share->expires_at);
                $item = BureaucracyEvidenceItem::query()->with('person')->find($share->evidence_id);
                if ($item !== null && $share->grantor_id !== null) {
                    $add($this->access->guardianAuthority($item->person, $share->grantor_id)?->expires_at);
                }
            }
        }
        $session = BureaucracyQuestionSession::query()->where('actor_id', $actor->id)->where('case_id', $person->dossier->id)
            ->where('jurisdiction', $input->jurisdiction)->where('expires_at', '>', $input->at->utc())->latest('id')->first();
        $add($session?->expires_at);
        $add($session?->questions()->whereNull('answered_at')->latest('id')->first()?->offer_expires_at);
        foreach ($timeline as $event) {
            if ($event['kind'] === 'appointment' && $event['state'] === 'recorded') {
                $add($event['starts_at']);
            }
        }
        usort($boundaries, fn ($a, $b) => $a <=> $b);

        return $boundaries[0]->toIso8601String();
    }

    private function factBoundaries(int $caseId, callable $add, ?array $keys = null): void
    {
        $query = BureaucracyCaseFact::query()->where('case_id', $caseId)->where('state', 'confirmed')->whereNull('superseded_at')->whereNotNull('reconfirm_at');
        if ($keys !== null) {
            $query->whereIn('key', $keys);
        }
        // Legacy fact timestamps are application-local; their casts are authoritative.
        // Unlike the newer timestamptz tables, parsing a raw aggregate as UTC changes their meaning.
        foreach ($query->get(['id', 'reconfirm_at']) as $fact) {
            $add($fact->reconfirm_at);
        }
    }
}
