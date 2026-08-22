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
        $profile = $this->profileEngine->build($case->user()->firstOrFail());

        $attributes = [
            ...$profile->attributes,
            'german_level' => $profile->germanLevel?->value,
        ];

        $factHistory = BureaucracyCaseFact::query()
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
