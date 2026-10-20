<?php

namespace App\Bureaucracy\Facts;

use App\Models\BureaucracyCase;
use App\Models\BureaucracyExtractionCandidate;
use App\Models\BureaucracyOutboxEvent;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyProcessingConsent;

/** Invoked inside the authorised fact command's transaction and case lock. */
final class AfterFactsChanged
{
    public function record(BureaucracyPerson $person, BureaucracyCase $case, array $keys): int
    {
        $case->increment('fact_version');
        $person->increment('record_version');
        $case->planSnapshots()->delete();
        BureaucracyProcessingConsent::query()->where('case_id', $case->id)->whereNull('withdrawn_at')->update([
            'withdrawn_at' => now()->utc(), 'state' => 'withdrawn', 'result' => null,
        ]);
        BureaucracyExtractionCandidate::query()->where('case_id', $case->id)->where('state', 'pending')
            ->update(['state' => 'invalidated', 'value' => null, 'confirmation_token' => null]);
        BureaucracyOutboxEvent::query()->create([
            'event_type' => 'facts.changed', 'aggregate_type' => 'case', 'aggregate_id' => $case->id,
            'aggregate_version' => $case->fact_version, 'dedupe_key' => "facts.changed:{$case->id}:{$case->fact_version}",
            'payload' => ['person_id' => $person->id, 'fact_keys' => array_values(array_unique($keys))], 'available_at' => now()->utc(),
        ]);

        return $case->fact_version;
    }
}
