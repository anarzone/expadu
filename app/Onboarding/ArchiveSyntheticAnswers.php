<?php

namespace App\Onboarding;

use App\Bureaucracy\Facts\AfterFactsChanged;
use App\Bureaucracy\Facts\FactSourceTrust;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyFactConflict;
use App\Models\BureaucracyPerson;

/** Only inside the authorised confirmation transaction; preview reads never change records. */
final class ArchiveSyntheticAnswers
{
    public function execute(BureaucracyPerson $person, BureaucracyCase $case): void
    {
        $samples = $case->facts()->whereIn('state', ['confirmed', 'candidate'])->lockForUpdate()->get()
            ->filter(FactSourceTrust::isSynthetic(...));
        if ($samples->isEmpty()) {
            return;
        }
        foreach ($samples as $sample) {
            // Keep the original values/provenance. A simulation ending is not a real-life status change.
            $sample->update(['state' => 'superseded', 'superseded_at' => now()]);
        }
        BureaucracyFactConflict::query()->where('case_id', $case->id)->where('status', 'unresolved')
            ->where(fn ($query) => $query->whereIn('existing_fact_id', $samples->modelKeys())->orWhereIn('candidate_fact_id', $samples->modelKeys()))
            ->update(['status' => 'obsolete', 'resolved_fact_id' => null, 'resolved_at' => null]);
        app(AfterFactsChanged::class)->record($person, $case, $samples->pluck('key')->all());
    }
}
