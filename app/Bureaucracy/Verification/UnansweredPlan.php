<?php

namespace App\Bureaucracy\Verification;

/**
 * Reads a person's plan for the moments the app cannot answer. Only process identifiers
 * and the jurisdiction leave this class: escalations never carry personal data.
 */
final class UnansweredPlan
{
    /** Processes this person may need whose verified content is missing right now. @return list<string> */
    public static function processes(array $plan): array
    {
        return collect($plan['coverage']['processes'] ?? [])
            ->filter(fn ($row) => ($row['relevance'] ?? null) !== 'not_relevant' && ($row['coverage']['status'] ?? null) === 'review_required')
            ->pluck('definition_id')->filter(fn ($id) => is_string($id))->values()->all();
    }

    /** An active catalogue that has nothing at all for this person. */
    public static function isEmpty(array $plan): bool
    {
        return ($plan['coverage']['state'] ?? null) === 'partial' && ($plan['actions'] ?? []) === []
            && ! collect($plan['coverage']['processes'] ?? [])->contains(fn ($row) => ($row['relevance'] ?? null) !== 'not_relevant');
    }
}
