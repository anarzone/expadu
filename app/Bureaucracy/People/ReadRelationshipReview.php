<?php

namespace App\Bureaucracy\People;

use App\Bureaucracy\Assessment\AssessmentFacts;
use App\Bureaucracy\Facts\ConfirmedFactView;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyRelationship;
use App\Models\User;

final class ReadRelationshipReview
{
    public function __construct(private PersonCommandScope $scope, private PersonAccess $access,
        private ConfirmedFactView $facts, private RelationshipFactView $linkedFacts) {}

    /** Review existing links and attributed reports. No values are copied or selected automatically. */
    public function for(User $actor, BureaucracyPerson $subject): array
    {
        return $this->scope->run($actor, $subject, AccessScope::ViewFacts, function ($person, $case) use ($actor): array {
            $links = BureaucracyRelationship::query()->where('person_id', $person->id)->whereNull('revoked_at')->orderBy('id')->get();
            $relatedPeople = BureaucracyPerson::query()->whereKey($links->pluck('related_person_id'))->orderBy('id')->sharedLock()->get()->keyBy('id');
            BureaucracyCase::query()->whereIn('person_id', $relatedPeople->keys())->orderBy('id')->sharedLock()->get(['id']);
            $reported = $this->facts->forCase($case, now()->toDateString());
            $canEdit = $this->access->allows($actor, $person, AccessScope::EditFacts);
            $canViewPlan = $this->access->allows($actor, $person, AccessScope::ViewPlan);
            $sponsorViews = [];
            $rows = [];
            foreach ($links as $link) {
                $related = $relatedPeople->get($link->related_person_id);
                $mayReadRelated = $related !== null && $this->access->allows($actor, $related, AccessScope::ViewFacts);
                $view = $link->type === 'sponsor' && $canViewPlan
                    ? $this->linkedFacts->forApplicant($actor, $person, $link->id, now()->toDateString())
                    : ['status' => 'access_unavailable', 'values' => []];
                if ($link->type === 'sponsor') {
                    $sponsorViews[] = $view;
                }
                $rows[] = ['id' => $link->id, 'related_person_id' => $link->related_person_id, 'type' => $link->type,
                    'effective_from' => $link->effective_from?->toDateString(), 'source' => $link->source,
                    'display_label' => $mayReadRelated ? $related->display_label : null,
                    'status' => $view['status'], 'linked_facts' => $view['values'], 'can_remove' => $canEdit,
                    'can_edit_related_facts' => $mayReadRelated && $this->access->allows($actor, $related, AccessScope::EditFacts)];
            }
            $combined = (new AssessmentFacts)->combine($reported, $sponsorViews);
            $reviews = [];
            foreach ($combined['conflict_origins'] as $key => $origin) {
                if ($origin !== 'local_assertions') {
                    $reviews[] = ['fact_key' => $key, 'reason' => $origin, 'available' => $canEdit,
                        'actions' => $origin === 'multiple_sponsors' ? ['remove_incorrect_relationship']
                            : ['review_attributed_report', 'review_linked_record_if_authorised', 'remove_incorrect_relationship']];
                }
            }
            $keys = array_flip(['sponsor', 'sponsor_current_title']);

            return ['schema_version' => 'bureaucracy.relationship-review.1', 'person_id' => $person->id,
                'record_version' => $person->record_version, 'fact_revision' => $case->fact_version,
                'reported_facts' => ['values' => array_intersect_key($reported['values'], $keys),
                    'states' => array_intersect_key($reported['states'], $keys), 'evidence' => array_intersect_key($reported['evidence'], $keys)],
                'relationships' => $rows, 'reviews' => $reviews];
        });
    }
}
