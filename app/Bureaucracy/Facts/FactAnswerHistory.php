<?php

namespace App\Bureaucracy\Facts;

use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;

/** Confirmed answer history for one fact. The caller must authorise fact-reading access first. */
final class FactAnswerHistory
{
    private const Operations = ['assertion' => 'asserted', 'correction' => 'corrected', 'change' => 'changed', 'conflict_resolution' => 'resolved'];

    public function __construct(private FactRegistry $registry) {}

    public function for(BureaucracyCase $case, string $key): array
    {
        $this->registry->definition($key);
        // Unconfirmed AI candidates and rejected/pending rows were never answers; synthetic QA rows are not this person's.
        $facts = $case->facts()->where('key', $key)->whereIn('state', ['confirmed', 'historical', 'superseded'])->whereNotNull('confirmed_at')
            ->orderBy('id')->get()->reject(FactSourceTrust::isSynthetic(...))->values();
        $byId = $facts->keyBy('id');
        $entries = [];
        $previous = null;
        foreach ($facts as $fact) {
            $operation = self::Operations[$fact->operation] ?? 'recorded';
            $before = match (true) {
                $fact->supersedes_fact_id !== null => $byId->get($fact->supersedes_fact_id),
                in_array($operation, ['changed', 'resolved'], true) => $previous,
                default => null,
            };
            $entries[] = ['fact_id' => $fact->id, 'operation' => $operation, 'state' => $fact->state, 'source' => $fact->source,
                'after' => $this->answer($fact), 'before' => $before === null ? null : ['fact_id' => $before->id, ...$this->answer($before)],
                'effective_from' => $fact->effective_from?->toDateString(), 'effective_until' => $fact->effective_until?->toDateString(),
                'end_date_unknown' => (bool) $fact->end_date_unknown,
                'recorded_at' => ($fact->recorded_at ?? $fact->confirmed_at)?->utc()->toIso8601String(),
                'confirmed_at' => $fact->confirmed_at?->utc()->toIso8601String(), 'superseded_at' => $fact->superseded_at?->utc()->toIso8601String()];
            $previous = $fact;
        }

        return ['schema_version' => 'bureaucracy.fact-history.1', 'person_id' => $case->person_id, 'key' => $key,
            'revision' => $case->fact_version, 'entries' => array_reverse($entries)];
    }

    private function answer(BureaucracyCaseFact $fact): array
    {
        $state = $fact->answer_state ?? 'value';

        return ['answer_state' => $state, 'value' => $state === 'value' ? $fact->value : null];
    }
}
