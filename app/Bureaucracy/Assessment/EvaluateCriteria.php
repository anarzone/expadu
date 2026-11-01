<?php

namespace App\Bureaucracy\Assessment;

use App\Profile\Applicability;
use Carbon\CarbonImmutable;

final class EvaluateCriteria
{
    public function evaluate(array $groups, array $facts, CarbonImmutable $at): array
    {
        if ($groups !== [] && ! array_is_list($groups)) {
            $groups = [$groups];
        }
        $rows = [];
        $alternatives = [];
        foreach ($groups as $index => $group) {
            $results = [];
            foreach ($group as $key => $expected) {
                // A goal chooses priority, never legal eligibility or exclusive identity.
                if ($key === 'case_goal') {
                    continue;
                }
                $result = $this->condition($key, $expected, $facts, $at);
                $results[$key] = $result;
                $rows[] = ['fact_key' => $key, 'alternative' => $index, 'status' => $result->value, 'reason' => 'criterion.'.$result->value];
            }
            $unmet = in_array(CriterionResult::Unmet, $results, true);
            $missing = array_keys(array_filter($results, fn ($result) => $result->unresolved()));
            $alternatives[] = ['status' => $unmet ? 'unmet' : ($missing !== [] ? 'unknown' : 'met'), 'missing' => $unmet ? [] : $missing];
        }
        $statuses = array_column($alternatives, 'status');
        $status = $groups === [] || in_array('met', $statuses, true) ? 'met' : (in_array('unknown', $statuses, true) ? 'unknown' : 'unmet');
        $missing = [];
        if ($status === 'unknown') {
            foreach ($alternatives as $alternative) {
                if ($alternative['status'] === 'unknown') {
                    $missing = [...$missing, ...$alternative['missing']];
                }
            }
        }

        return ['status' => $status, 'criteria' => $rows, 'alternatives' => $alternatives, 'missing' => array_values(array_unique($missing))];
    }

    public function condition(string $key, mixed $expected, array $facts, CarbonImmutable $at): CriterionResult
    {
        $state = $facts['states'][$key] ?? (array_key_exists($key, $facts['values'] ?? []) ? 'value' : 'unknown');
        if ($state === 'conflict') {
            return CriterionResult::Conflict;
        }
        if ($state === 'invalid') {
            return CriterionResult::Invalid;
        }
        if ($state === 'needs_reconfirmation') {
            return CriterionResult::Reconfirmation;
        }
        if ($state === 'not_applicable' && is_array($expected) && array_keys($expected) === ['present'] && is_bool($expected['present'])) {
            return $expected['present'] ? CriterionResult::Unmet : CriterionResult::Met;
        }
        if ($state !== 'value' || ! array_key_exists($key, $facts['values'] ?? []) || $facts['values'][$key] === null) {
            return CriterionResult::Unknown;
        }
        $value = $facts['values'][$key];
        if (is_array($expected) && ! array_is_list($expected) && in_array(array_key_first($expected), ['at_least_months_ago', 'months_ago_between'], true)) {
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
                return CriterionResult::Invalid;
            }
            [$year, $month, $day] = array_map(intval(...), explode('-', $value));
            if (! checkdate($month, $day, $year) || $value > $at->toDateString()) {
                return CriterionResult::Invalid;
            }
        }
        if (! is_array($expected) && get_debug_type($value) !== get_debug_type($expected)) {
            return CriterionResult::Invalid;
        }

        return match (Applicability::evaluateConditionAt($expected, $value, $at)) {
            Applicability::Yes => CriterionResult::Met,
            Applicability::No => CriterionResult::Unmet,
            Applicability::Unknown => CriterionResult::Unknown,
        };
    }
}
