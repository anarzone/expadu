<?php

namespace App\Bureaucracy\Facts;

use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Bureaucracy\People\PersonCommandScope;
use App\Models\BureaucracyFactConflict;
use App\Models\BureaucracyPerson;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;
use DomainException;

final class ReadFactConflicts
{
    public function __construct(private PersonCommandScope $scope, private PersonAccess $access,
        private ConfirmedFactView $view, private FactRegistry $registry) {}

    public function for(User $actor, BureaucracyPerson $person): array
    {
        return $this->scope->run($actor, $person, AccessScope::ViewFacts, function ($person, $case) use ($actor): array {
            $view = $this->view->forCase($case, now()->toDateString());
            $active = $this->view->activeAssertions($case, now()->toDateString());
            $legacy = BureaucracyFactConflict::query()->where('case_id', $case->id)->actionable()->with(['existingFact', 'candidateFact'])->get()
                ->reject(FactSourceTrust::hasSyntheticParticipant(...));
            $result = [];
            foreach ($view['states'] as $key => $state) {
                if ($state !== 'conflict') {
                    continue;
                }
                try {
                    $definition = $this->registry->definition($key);
                } catch (DomainException) {
                    $result[] = ['fact_key' => $key, 'resolution_available' => false, 'reason' => 'unsupported_fact_requires_manual_review'];

                    continue;
                }
                $assertions = $active->where('key', $key)->values();
                foreach ($legacy->where('fact_key', $key) as $conflict) {
                    if ($assertions->contains('id', $conflict->existing_fact_id)) {
                        $assertions->push($conflict->candidateFact);
                    }
                }
                $choices = $assertions->unique('id')->sortBy('id')->map(function ($fact) use ($definition): array {
                    $state = $fact->answer_state ?? 'value';
                    try {
                        $value = $state === 'value' ? $definition->normalize($fact->value) : null;
                    } catch (DomainException) {
                        $value = null;
                        $state = 'invalid';
                    }

                    return ['fact_id' => $fact->id, 'value' => $value, 'answer_state' => $state,
                        'confirmation' => $fact->state === 'candidate' ? 'unconfirmed_candidate' : 'previously_confirmed',
                        'source' => $fact->source, 'recorded_at' => ($fact->recorded_at ?? $fact->confirmed_at)?->toIso8601String(),
                        'effective_from' => $fact->effective_from?->toDateString()];
                })->values()->all();
                $result[] = ['fact_key' => $key, 'question' => $definition->question, 'why' => $definition->why,
                    'resolution_available' => $this->access->allows($actor, $person, AccessScope::EditFacts), 'choices' => $choices,
                    'answer_schema' => ['type' => $definition->type, 'options' => $definition->options, 'allows_not_applicable' => $definition->allowsNotApplicable],
                    'expected_revision' => $case->fact_version, 'confirmation_scope' => 'current_from_today',
                    'review_token' => ProcessingConsentStore::digest(['schema' => 'bureaucracy.conflict-review.1',
                        'actor_id' => $actor->id, 'person_id' => $person->id, 'authority' => $this->access->authorityToken($actor, $person),
                        'revision' => $case->fact_version, 'registry' => $this->registry->version(), 'key' => $key, 'choices' => $choices,
                        'date' => now()->toDateString()])];
            }

            return ['schema_version' => 'bureaucracy.conflicts.1', 'person_id' => $person->id, 'revision' => $case->fact_version, 'conflicts' => $result];
        });
    }
}
