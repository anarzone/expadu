<?php

namespace App\Bureaucracy\Facts;

use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyFactConflict;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

final class ConfirmedFactView
{
    public function __construct(private FactRegistry $registry, private FactSourceTrust $trust) {}

    /** Internal assessor input; callers must authorise the subject before exposing it. */
    public function forCase(BureaucracyCase $case, string $onDate): array
    {
        if (CalendarDate::parse($onDate) === null) {
            throw new DomainException('An exact evaluation date is required.');
        }
        $case = BureaucracyCase::query()->findOrFail($case->id);
        $view = ['revision' => $case->fact_version, 'values' => [], 'states' => [], 'evidence' => []];
        if ($case->status !== 'active') {
            return $view;
        }
        $facts = $case->facts()->whereIn('state', ['confirmed', 'historical'])->whereNull('superseded_at')->orderBy('id')->get()
            ->reject(FactSourceTrust::isSynthetic(...));
        foreach ($facts->groupBy('key') as $key => $history) {
            $eligible = $history->filter(fn ($fact) => $this->within($fact, $onDate))->values();
            if ($eligible->isEmpty()) {
                $view['states'][$key] = 'unknown';

                continue;
            }
            $assertions = $eligible->map(fn ($fact) => json_encode([$fact->answer_state ?? 'value', $fact->value]))->unique();
            if ($assertions->count() > 1 || $eligible->contains(fn ($fact) => $fact->state === 'historical'
                && ($fact->provenance['closed_period']['legacy_candidate_dispute'] ?? false))) {
                $view['states'][$key] = 'conflict';

                continue;
            }
            try {
                $definition = $this->registry->definition($key);
            } catch (DomainException) {
                $view['states'][$key] = 'invalid';

                continue;
            }
            // Identical duplicate values are not a conflict, but their provenance is not interchangeable.
            $fact = $eligible->last(function ($assertion) use ($definition): bool {
                $source = $this->trust->sourceFor($assertion, $definition);

                return $source !== null && in_array($source, $definition->permissibleSources, true);
            });
            if ($fact === null) {
                $view['states'][$key] = 'needs_reconfirmation';

                continue;
            }
            $state = $fact->answer_state ?? 'value';
            $view['states'][$key] = $state;
            $view['evidence'][$key] = ['fact_id' => $fact->id, 'source' => $fact->source,
                'context_id' => $fact->context_id ?? 'fact:'.$fact->id,
                'effective_from' => $fact->effective_from?->toDateString(),
                'effective_until' => $fact->effective_until?->toDateString(),
                'end_date_unknown' => $fact->end_date_unknown];
            if ($state !== 'value') {
                continue;
            }
            try {
                $view['values'][$key] = $definition->normalize($fact->value);
            } catch (DomainException) {
                $view['states'][$key] = 'invalid';
            }
        }
        foreach (BureaucracyFactConflict::query()->where('case_id', $case->id)->actionable()->with(['existingFact', 'candidateFact'])->get() as $conflict) {
            if (FactSourceTrust::hasSyntheticParticipant($conflict)) {
                continue;
            }
            if ($conflict->existingFact?->case_id === $case->id && $this->within($conflict->existingFact, $onDate)
                && ($conflict->candidateFact->state === 'candidate' || $this->within($conflict->candidateFact, $onDate))) {
                unset($view['values'][$conflict->fact_key]);
                $view['states'][$conflict->fact_key] = 'conflict';
            }
        }

        return $view;
    }

    /** Internal review input. The caller must authorise fact-reading access first. */
    public function activeAssertions(BureaucracyCase $case, string $onDate): Collection
    {
        return $case->facts()->whereIn('state', ['confirmed', 'historical'])->whereNull('superseded_at')->orderBy('id')->get()
            ->reject(FactSourceTrust::isSynthetic(...))
            ->filter(fn ($fact) => $this->within($fact, $onDate));
    }

    private function within(BureaucracyCaseFact $fact, string $onDate): bool
    {
        if ($fact->confirmed_at === null) {
            return false;
        }
        if ($fact->state === 'historical') {
            $beforeResolution = $fact->provenance['closed_period'] ?? null;
            if (($beforeResolution['state'] ?? null) === 'confirmed' && $beforeResolution['closed_on'] !== null) {
                // Reproduce what was knowable before the current-only resolution. In particular, an
                // undated competing assertion still quarantines a disputed past period after it is closed.
                $recordedOn = ($fact->recorded_at ?? $fact->confirmed_at)->copy()->setTimezone(config('app.timezone'))->toDateString();

                return $onDate < $beforeResolution['closed_on']
                    && ($fact->effective_from?->toDateString() ?? $recordedOn) <= $onDate
                    && ($beforeResolution['effective_until'] === null || $onDate < $beforeResolution['effective_until'])
                    && ($fact->reconfirm_at === null || $fact->reconfirm_at->greaterThan(CalendarDate::parse($onDate)));
            }

            return $fact->effective_from !== null && $fact->effective_until !== null && ! $fact->end_date_unknown
                && $fact->effective_from->toDateString() <= $onDate && $onDate < $fact->effective_until->toDateString();
        }
        $evaluationTime = $onDate === now()->toDateString() ? now() : CalendarDate::parse($onDate);
        if ($fact->reconfirm_at !== null && $fact->reconfirm_at->lessThanOrEqualTo($evaluationTime)) {
            return false;
        }
        if ($fact->effective_until !== null && $onDate >= $fact->effective_until->toDateString()) {
            return false;
        }
        if ($fact->effective_from !== null) {
            return $fact->effective_from->toDateString() <= $onDate;
        }

        return $onDate >= ($fact->recorded_at ?? $fact->confirmed_at)->setTimezone(config('app.timezone'))->toDateString();
    }
}
