<?php

namespace App\Bureaucracy\Facts;

use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyCaseQuestion;
use App\Models\BureaucracyFactConflict;

/** Classifies existing assertions without rewriting their values, dates or original provenance. */
final class FactSourceTrust
{
    public static function isSynthetic(BureaucracyCaseFact $fact): bool
    {
        return is_string($fact->source)
            && (str_starts_with($fact->source, 'qa_scenario:') || str_starts_with($fact->source, 'qa_persona:'));
    }

    public static function hasSyntheticParticipant(BureaucracyFactConflict $conflict): bool
    {
        return ($conflict->existingFact !== null && self::isSynthetic($conflict->existingFact))
            || ($conflict->candidateFact !== null && self::isSynthetic($conflict->candidateFact));
    }

    /** The former onboarding adapter copied these inputs; it computed citizenship, purpose and permit track. */
    private const EXPLICIT_LEGACY_ONBOARDING = [
        'entry_mode', 'visa_expires_at', 'current_residence_title', 'residence_title_expires_at',
        'case_goal', 'sponsor_current_title', 'german_level',
    ];

    public function sourceFor(BureaucracyCaseFact $fact, FactDefinition $definition): ?string
    {
        if ($fact->source === 'legacy_profile') {
            return ($fact->provenance['validation'] ?? null) === 'verified_explicit_legacy_input' ? 'legacy_profile' : null;
        }
        if ($fact->operation === null && $fact->source === 'onboarding') {
            if (! in_array($fact->key, self::EXPLICIT_LEGACY_ONBOARDING, true)) {
                return null;
            }

            return $definition->subjectScope === 'related_person_report' ? 'attributed_report' : 'onboarding';
        }
        if ($fact->source === 'structured_interview') {
            if (! is_string($fact->source_reference) || ! preg_match('/\Aquestion:([1-9][0-9]*)\z/', $fact->source_reference, $matches)) {
                return null;
            }
            $answered = BureaucracyCaseQuestion::query()->whereKey($matches[1])->where('case_id', $fact->case_id)
                ->where('fact_key', $fact->key)->whereNotNull('answered_at')->whereIn('outcome', ['answered', 'conflict'])->exists();

            return $answered ? ($definition->subjectScope === 'related_person_report' ? 'attributed_report' : 'manual') : null;
        }

        return in_array($fact->source, $definition->permissibleSources, true) ? $fact->source : null;
    }
}
