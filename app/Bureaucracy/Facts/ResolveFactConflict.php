<?php

namespace App\Bureaucracy\Facts;

use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Bureaucracy\People\PersonCommandScope;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyFactConflict;
use App\Models\BureaucracyPerson;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ResolveFactConflict
{
    public function __construct(private PersonCommandScope $scope, private PersonAccess $access, private ReadFactConflicts $reviews,
        private FactRegistry $registry, private TemporalFactValidator $validator, private ConfirmedFactView $view, private AfterFactsChanged $changed) {}

    /** Confirm what is true now; earlier overlapping periods remain attributed, uncertain history. */
    public function execute(User $actor, BureaucracyPerson $person, string $key, mixed $value, string $answerState,
        int $expectedRevision, string $reviewToken, string $requestId): array
    {
        if (! Str::isUuid($requestId)) {
            throw ValidationException::withMessages(['request_id' => 'Use an identifier for this confirmation.']);
        }

        return $this->scope->run($actor, $person, AccessScope::EditFacts, function ($person, $case) use ($actor, $key, $value, $answerState, $expectedRevision, $reviewToken, $requestId): array {
            $this->access->authorize($actor, $person, AccessScope::ViewFacts);
            $definition = $this->registry->definition($key);
            $normal = $this->validator->normalize($definition, $value, $answerState, now()->toDateString());
            $source = $definition->subjectScope === 'related_person_report' ? 'attributed_report' : 'manual';
            if (! in_array($source, $definition->permissibleSources, true)) {
                throw ValidationException::withMessages(['value' => 'This fact requires another confirmation method.']);
            }
            $reference = 'conflict-resolution:'.$actor->id.':'.strtolower($requestId);
            $fingerprint = ProcessingConsentStore::digest([$person->id, $key, $normal, $answerState, $expectedRevision, $reviewToken,
                $this->access->authorityToken($actor, $person)]);
            $replay = BureaucracyCaseFact::query()->where('source_reference', $reference)->first();
            if ($replay !== null) {
                if ($replay->case_id !== $case->id || ($replay->provenance['resolution_fingerprint'] ?? null) !== $fingerprint) {
                    throw new ConflictHttpException('This confirmation identifier was already used for different information.');
                }

                return $replay->provenance['receipt'];
            }
            $review = collect($this->reviews->for($actor, $person)['conflicts'])->firstWhere('fact_key', $key);
            if ($case->fact_version !== $expectedRevision || ! ($review['resolution_available'] ?? false)
                || ! hash_equals($review['review_token'], $reviewToken)) {
                throw new ConflictHttpException('These answers changed. Review the current choices before confirming.');
            }
            $dependentKeys = (array) config('bureaucracy_fact_contexts.'.$key, []);
            $values = $this->view->forCase($case, now()->toDateString())['values'];
            foreach ([$key, ...$dependentKeys] as $retiredKey) {
                unset($values[$retiredKey]);
            }
            if ($answerState === 'value') {
                $values[$key] = $normal;
            }
            $this->validator->validateContext($values);
            $ids = array_column($review['choices'], 'fact_id');
            $retired = $case->facts()->where(function ($query) use ($ids, $key, $dependentKeys) {
                $query->whereIn('id', $ids)->orWhere(fn ($query) => $query->whereIn('key', [$key, ...$dependentKeys])->where('state', 'confirmed'));
            })->lockForUpdate()->get();
            $fact = $case->facts()->create(['key' => $key, 'value' => $normal, 'answer_state' => $answerState, 'state' => 'confirmed',
                'source' => $source, 'source_reference' => $reference, 'operation' => 'conflict_resolution',
                'recorded_by' => $actor->id, 'recorded_at' => now()->utc(), 'confirmed_at' => now(),
                'reconfirm_at' => now()->addDays($definition->reconfirmAfterDays), 'effective_from' => now()->toDateString(),
                'context_id' => (string) Str::uuid(), 'provenance' => ['input_method' => 'structured', 'subject_scope' => $definition->subjectScope,
                    'registry_version' => $this->registry->version(), 'resolved_assertion_ids' => $ids, 'resolution_fingerprint' => $fingerprint]]);
            $history = app(PreserveFactHistory::class)->beforeClosing($retired, now()->toDateString());
            foreach ($retired as $prior) {
                $provenance = [...$history[$prior->id], 'closed_by_resolution_id' => $fact->id];
                $prior->update($prior->state === 'candidate'
                    ? ['state' => 'superseded', 'superseded_at' => now(), 'provenance' => $provenance]
                    : ['state' => 'historical', 'effective_until' => now()->toDateString(), 'end_date_unknown' => $prior->effective_from === null, 'provenance' => $provenance]);
            }
            $conflicts = BureaucracyFactConflict::query()->where('case_id', $case->id)->where('status', 'unresolved')
                ->where(fn ($query) => $query->whereIn('existing_fact_id', $retired->modelKeys())->orWhereIn('candidate_fact_id', $retired->modelKeys()))->lockForUpdate()->get();
            foreach ($conflicts as $conflict) {
                $isResolution = $conflict->fact_key === $key;
                $conflict->update(['status' => $isResolution ? 'resolved' : 'obsolete', 'resolved_fact_id' => $isResolution ? $fact->id : null,
                    'resolved_at' => $isResolution ? now() : null]);
            }
            $revision = $this->changed->record($person, $case, array_values(array_unique([$key, ...$retired->pluck('key')->all()])));
            $receipt = ['fact_id' => $fact->id, 'revision' => $revision, 'status' => 'resolved'];
            $fact->update(['provenance' => [...$fact->provenance, 'receipt' => $receipt]]);

            return $receipt;
        });
    }
}
