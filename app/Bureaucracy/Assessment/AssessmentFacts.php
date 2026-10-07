<?php

namespace App\Bureaucracy\Assessment;

final class AssessmentFacts
{
    /** Merge only the minimum sponsor facts supplied by the authorised relationship view. */
    public function combine(array $facts, array $relationships): array
    {
        $facts['values'] ??= [];
        $facts['states'] ??= [];
        $facts['conflict_origins'] = [];
        foreach ($facts['states'] as $key => $state) {
            if ($state === 'conflict') {
                $facts['conflict_origins'][$key] = 'local_assertions';
            }
        }
        $keys = ['sponsor', 'sponsor_current_title'];
        $current = array_values(array_filter($relationships, fn ($link) => in_array($link['status'] ?? null, ['available', 'access_unavailable'], true)));
        if (count($current) > 1) {
            // Multiple current sponsors need relationship clarification, not a field-by-field merge.
            foreach ($keys as $key) {
                unset($facts['values'][$key]);
                $facts['states'][$key] = 'conflict';
                $facts['conflict_origins'][$key] ??= 'multiple_sponsors';
            }

            return $facts;
        }
        foreach ($current as $relationship) {
            if (($relationship['status'] ?? null) !== 'available') {
                continue;
            }
            $linkedValues = array_intersect_key($relationship['values'] ?? [], array_flip($keys));
            foreach ($keys as $key) {
                if (! array_key_exists($key, $linkedValues) && array_key_exists($key, $facts['values'])) {
                    // Old attributed reports are not identity-bound to this linked person.
                    unset($facts['values'][$key]);
                    $facts['states'][$key] = 'needs_reconfirmation';
                }
            }
            foreach ($linkedValues as $key => $value) {
                if (($facts['states'][$key] ?? null) === 'conflict') {
                    continue;
                }
                if (array_key_exists($key, $facts['values']) && $facts['values'][$key] !== $value) {
                    unset($facts['values'][$key]);
                    $facts['states'][$key] = 'conflict';
                    $facts['conflict_origins'][$key] = 'linked_report_mismatch';

                    continue;
                }
                $facts['values'][$key] = $value;
                $facts['states'][$key] = 'value';
            }
        }

        return $facts;
    }
}
