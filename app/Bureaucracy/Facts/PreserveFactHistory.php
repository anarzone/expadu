<?php

namespace App\Bureaucracy\Facts;

use App\Models\BureaucracyFactConflict;
use Illuminate\Database\Eloquent\Collection;

final class PreserveFactHistory
{
    /** Capture before retiring either side; a current answer cannot silently settle a past dispute. */
    public function beforeClosing(Collection $assertions, ?string $closedOn): array
    {
        $disputed = BureaucracyFactConflict::query()->whereIn('existing_fact_id', $assertions->modelKeys())->actionable()
            ->whereHas('candidateFact', fn ($query) => $query->where('state', 'candidate'))->with(['existingFact', 'candidateFact'])->get()
            ->reject(FactSourceTrust::hasSyntheticParticipant(...))->pluck('existing_fact_id')->flip()->all();

        return $assertions->mapWithKeys(fn ($fact) => [$fact->id => [...($fact->provenance ?? []),
            'closed_period' => ['state' => $fact->state, 'closed_on' => $closedOn,
                'effective_until' => $fact->effective_until?->toDateString(), 'end_date_unknown' => $fact->end_date_unknown,
                'legacy_candidate_dispute' => isset($disputed[$fact->id])],
        ]])->all();
    }
}
