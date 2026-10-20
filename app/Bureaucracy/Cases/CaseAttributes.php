<?php

namespace App\Bureaucracy\Cases;

use App\Bureaucracy\Facts\ConfirmedBureaucracyAttributes;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyFactConflict;

/**
 * The attribute bag every `applies_if` is evaluated against: profile
 * attributes underneath, confirmed case facts on top.
 *
 * A fact that exists in history but is no longer confirmed is written back as
 * an explicit null rather than left absent — "asked and unanswered" has to read
 * as Unknown, not as a value that was never sought.
 */
final class CaseAttributes
{
    public function __construct(private ConfirmedBureaucracyAttributes $confirmedAttributes) {}

    /**
     * @return array<string, mixed>
     */
    public function for(BureaucracyCase $case): array
    {
        // Only an unsaved QA case may supply in-memory relations. A persisted
        // case must see retirements, corrections and conflicts since it loaded.
        $user = $case->exists ? $case->user()->first() : $case->user;

        $attributes = $user !== null ? $this->confirmedAttributes->forUser($user) : [];
        $explicitProfile = $attributes;

        $factHistory = $case->exists
            ? BureaucracyCaseFact::query()
                ->where('case_id', $case->getKey())
                ->orderBy('id')
                ->get()
            : ($case->relationLoaded('facts') ? $case->facts->sortBy('id')->values() : collect());

        foreach ($factHistory as $fact) {
            $attributes[$fact->key] = null;
        }

        foreach ($factHistory as $fact) {
            if ($fact->state !== 'confirmed'
                || $fact->confirmed_at === null
                || $fact->superseded_at !== null
                || ($fact->reconfirm_at !== null && ! $fact->reconfirm_at->isFuture())) {
                continue;
            }

            // Earlier bootstraps recorded inferred branch defaults as confirmed.
            // Preserve those rows, but require corroboration by an explicit input.
            if ($fact->source === 'legacy_profile' && ($explicitProfile[$fact->key] ?? null) !== $fact->value) {
                continue;
            }

            $attributes[$fact->key] = $fact->value;
        }

        $conflictKeys = $case->exists
            ? BureaucracyFactConflict::query()->where('case_id', $case->getKey())->actionable()->pluck('fact_key')
            : ($case->relationLoaded('conflicts')
                ? $case->conflicts->where('status', 'unresolved')->pluck('fact_key') : collect());
        foreach ($conflictKeys as $key) {
            $attributes[$key] = null;
        }

        return $this->confirmedAttributes->validated($attributes);
    }
}
