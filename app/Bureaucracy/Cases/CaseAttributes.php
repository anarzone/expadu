<?php

namespace App\Bureaucracy\Cases;

use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Profile\ProfileEngine;

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
    public function __construct(private ProfileEngine $profileEngine) {}

    /**
     * @return array<string, mixed>
     */
    public function for(BureaucracyCase $case): array
    {
        // A relation that is already loaded is used as-is. The read-only demo
        // renders a persona from an unsaved case whose user and facts exist
        // only in memory, and re-querying them would either fail or, worse,
        // silently read a different user's row.
        $user = $case->relationLoaded('user')
            ? $case->user
            : $case->user()->firstOrFail();

        $profile = $this->profileEngine->build($user);

        $attributes = [
            ...$profile->attributes,
            'german_level' => $profile->germanLevel?->value,
        ];

        $factHistory = $case->relationLoaded('facts')
            ? $case->facts->sortBy('id')->values()
            : BureaucracyCaseFact::query()
                ->where('case_id', $case->getKey())
                ->orderBy('id')
                ->get();

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

            $attributes[$fact->key] = $fact->value;
        }

        return $attributes;
    }
}
