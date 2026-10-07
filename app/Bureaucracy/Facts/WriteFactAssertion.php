<?php

namespace App\Bureaucracy\Facts;

use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\PersonAccess;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyFactConflict;
use App\Models\BureaucracyPerson;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class WriteFactAssertion
{
    public function __construct(private FactRegistry $registry, private PersonAccess $access, private TemporalFactValidator $validator, private ConfirmedFactView $view) {}

    public function write(User $actor, BureaucracyPerson $subject, ?string $key, mixed $value, ?string $effectiveFrom, int $expectedRevision, string $answerState, ?int $correctsId = null, FactInputMethod $method = FactInputMethod::Structured): BureaucracyCaseFact
    {
        return DB::transaction(function () use ($actor, $subject, $key, $value, $effectiveFrom, $expectedRevision, $answerState, $correctsId, $method): BureaucracyCaseFact {
            User::query()->whereKey($actor->id)->lock('for no key update')->firstOrFail();
            $person = BureaucracyPerson::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $person, AccessScope::EditFacts);
            $case = BureaucracyCase::query()->where('person_id', $person->id)->where('status', 'active')->lockForUpdate()->firstOrFail();
            $prior = null;
            if ($correctsId !== null) {
                $prior = $case->facts()->whereKey($correctsId)->lockForUpdate()->first();
                if ($prior === null || FactSourceTrust::isSynthetic($prior)) {
                    throw new AuthorizationException;
                }
                $key = $prior->key;
                $effectiveFrom = $prior->effective_from?->toDateString();
            }
            $definition = $this->registry->definition($key);
            $source = $definition->subjectScope === 'related_person_report' ? 'attributed_report' : $method->source();
            if (! in_array($source, $definition->permissibleSources, true)) {
                throw ValidationException::withMessages(['value' => 'This fact requires a different confirmation method.']);
            }
            $normalized = $this->validator->normalize($definition, $value, $answerState, $effectiveFrom);
            $currentView = $this->view->forCase($case, now()->toDateString());
            if (($currentView['states'][$key] ?? null) === 'conflict') {
                throw new ConflictHttpException('Review and explicitly confirm the conflicting answers before changing this fact.');
            }
            $current = null;
            if ($correctsId !== null) {
                $replay = $case->facts()->where('supersedes_fact_id', $correctsId)->whereIn('state', ['confirmed', 'historical'])->first();
                if ($replay !== null && $replay->value === $normalized && $replay->answer_state === $answerState) {
                    return $replay;
                }
                if (! in_array($prior->state, ['confirmed', 'historical'], true)) {
                    throw new ConflictHttpException('This assertion has already been replaced. Refresh the history.');
                }
            } else {
                $current = $case->facts()->where('key', $key)->where('state', 'confirmed')->lockForUpdate()->get()->reject(FactSourceTrust::isSynthetic(...));
                $prior = $current->firstWhere('id', $currentView['evidence'][$key]['fact_id'] ?? null) ?? $current->last();
                if ($prior !== null && $prior->value === $normalized && ($prior->answer_state ?? 'value') === $answerState
                    && ($currentView['states'][$key] ?? null) === $answerState
                    && ($currentView['evidence'][$key]['fact_id'] ?? null) === $prior->id
                    && $prior->effective_from?->toDateString() === $effectiveFrom
                    && ($prior->reconfirm_at === null || $prior->reconfirm_at->greaterThan(now()))) {
                    return $prior;
                }
            }
            if ($case->fact_version !== $expectedRevision) {
                throw new ConflictHttpException('The record changed while you were answering. Refresh before saving.');
            }
            if ($correctsId === null && $effectiveFrom !== null) {
                $history = $case->facts()->where('key', $key)->whereIn('state', ['confirmed', 'historical'])->whereNull('superseded_at')->get()->reject(FactSourceTrust::isSynthetic(...));
                if ($history->contains(fn ($record) => ($record->effective_from !== null && $record->effective_from->toDateString() >= $effectiveFrom)
                    || ($record->effective_until !== null && $record->effective_until->toDateString() > $effectiveFrom))) {
                    throw ValidationException::withMessages(['effective_from' => 'A new change must follow the existing periods. Use a correction to fix an earlier answer.']);
                }
            }
            $isHistoricalCorrection = $correctsId !== null && $prior->state === 'historical';
            if (! $isHistoricalCorrection) {
                $values = $this->view->forCase($case, now()->toDateString())['values'];
                unset($values[$key]);
                if ($answerState === 'value') {
                    $values[$key] = $normalized;
                }
                $this->validator->validateContext($values);
            }
            $this->validateAffectedPeriod($case, $key, $normalized, $answerState, $effectiveFrom, $isHistoricalCorrection ? $prior->effective_until?->toDateString() : null);
            $reconfirms = $correctsId === null && $prior !== null && $prior->value === $normalized
                && ($prior->answer_state ?? 'value') === $answerState && $prior->effective_from?->toDateString() === $effectiveFrom;
            $operation = $correctsId !== null ? 'correction' : ($prior === null || $reconfirms ? 'assertion' : 'change');
            $fact = $case->facts()->create([
                'key' => $key, 'value' => $normalized, 'answer_state' => $answerState,
                'state' => $isHistoricalCorrection ? 'historical' : 'confirmed',
                'source' => $source,
                'operation' => $operation, 'recorded_by' => $actor->id, 'recorded_at' => now()->utc(),
                'confirmed_at' => now(), 'reconfirm_at' => now()->addDays($definition->reconfirmAfterDays),
                'effective_from' => $effectiveFrom,
                'effective_until' => $isHistoricalCorrection ? $prior->effective_until : null,
                'end_date_unknown' => $isHistoricalCorrection && $prior->end_date_unknown,
                'supersedes_fact_id' => $correctsId,
                'context_id' => $correctsId === null && ! $reconfirms ? (string) Str::uuid() : ($prior->context_id ?? 'fact:'.$prior->id),
                'provenance' => ['input_method' => $method->value, 'subject_scope' => $definition->subjectScope, 'registry_version' => $this->registry->version()],
            ]);
            if ($prior !== null) {
                if ($correctsId !== null) {
                    $prior->update(['state' => 'superseded', 'superseded_at' => now()]);
                } else {
                    // A real change closes every duplicate/expired current assertion, not merely the first row.
                    $history = app(PreserveFactHistory::class)->beforeClosing($current, $effectiveFrom);
                    foreach ($current as $previous) {
                        $previous->update(['state' => 'historical', 'effective_until' => $effectiveFrom,
                            'end_date_unknown' => $effectiveFrom === null, 'provenance' => $history[$previous->id]]);
                    }
                }
                $retiredIds = $correctsId !== null ? [$prior->id] : $current->modelKeys();
                BureaucracyFactConflict::query()->where('case_id', $case->id)->where('status', 'unresolved')
                    ->where(fn ($query) => $query->whereIn('existing_fact_id', $retiredIds)->orWhereIn('candidate_fact_id', $retiredIds))
                    ->update(['status' => 'obsolete', 'resolved_fact_id' => null, 'resolved_at' => null]);
            }
            $changedKeys = [$key];
            if (! $isHistoricalCorrection && $prior !== null
                && (($correctsId === null && ! $reconfirms) || ($key === 'current_residence_title' && $prior->value !== $normalized))) {
                foreach ((array) config('bureaucracy_fact_contexts.'.$key, []) as $dependentKey) {
                    $this->registry->definition($dependentKey);
                    $dependents = $case->facts()->where('key', $dependentKey)->where('state', 'confirmed')->lockForUpdate()->get();
                    $history = app(PreserveFactHistory::class)->beforeClosing($dependents, $effectiveFrom);
                    foreach ($dependents as $dependent) {
                        $dependent->update(['state' => 'historical', 'effective_until' => $effectiveFrom,
                            'end_date_unknown' => $effectiveFrom === null, 'provenance' => $history[$dependent->id]]);
                        BureaucracyFactConflict::query()->where('case_id', $case->id)->where('status', 'unresolved')
                            ->where(fn ($query) => $query->where('existing_fact_id', $dependent->id)->orWhere('candidate_fact_id', $dependent->id))
                            ->update(['status' => 'obsolete', 'resolved_fact_id' => null, 'resolved_at' => null]);
                        $changedKeys[] = $dependentKey;
                    }
                }
            }
            app(AfterFactsChanged::class)->record($person, $case, $changedKeys);

            return $fact->fresh();
        });
    }

    /** Validate every affected interval boundary, not only the present-day view. */
    private function validateAffectedPeriod(BureaucracyCase $case, string $key, mixed $value, string $answerState, ?string $from, ?string $until): void
    {
        if ($from === null || ! in_array($key, ['arrival_planned', 'arrival_date', 'moved_in_at'], true)) {
            return;
        }
        $dates = [$from];
        foreach ($case->facts()->whereIn('key', ['arrival_planned', 'arrival_date', 'moved_in_at'])->whereIn('state', ['confirmed', 'historical'])->get() as $fact) {
            foreach ([$fact->effective_from, $fact->effective_until, $fact->recorded_at ?? $fact->confirmed_at] as $boundary) {
                $date = $boundary?->copy()->setTimezone(config('app.timezone'))->toDateString();
                if ($date !== null && $date >= $from && $date <= now()->toDateString() && ($until === null || $date < $until)) {
                    $dates[] = $date;
                }
            }
        }
        foreach (array_unique($dates) as $date) {
            $values = $this->view->forCase($case, $date)['values'];
            unset($values[$key]);
            if ($answerState === 'value') {
                $values[$key] = $value;
            }
            $this->validator->validateContext($values);
        }
    }
}
