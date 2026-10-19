<?php

namespace App\Bureaucracy\Facts;

use DomainException;
use Illuminate\Validation\ValidationException;

final class TemporalFactValidator
{
    public function normalize(FactDefinition $definition, mixed $value, string $answerState, ?string $effectiveFrom): mixed
    {
        if (! in_array($answerState, ['value', 'unknown', 'declined', 'not_applicable'], true)
            || ($answerState === 'not_applicable' && ! $definition->allowsNotApplicable)) {
            throw ValidationException::withMessages(['answer_state' => 'This answer state is not available for this question.']);
        }
        if ($effectiveFrom !== null && CalendarDate::historical($effectiveFrom) === null) {
            throw ValidationException::withMessages(['effective_from' => 'Use a real date on or before today, or leave the date unknown.']);
        }
        if ($answerState !== 'value') {
            if ($value !== null) {
                throw ValidationException::withMessages(['value' => 'An unknown or declined answer cannot contain a value.']);
            }

            return null;
        }
        try {
            return $definition->normalize($value);
        } catch (DomainException) {
            throw ValidationException::withMessages(['value' => 'Use a complete value that matches this question.']);
        }
    }

    public function validateContext(array $values): void
    {
        if (($values['arrival_planned'] ?? null) === true && (isset($values['arrival_date']) || isset($values['moved_in_at']))) {
            throw ValidationException::withMessages(['value' => 'This move is still marked as planned. Confirm arrival before recording an actual arrival or move-in.']);
        }
        if (isset($values['arrival_date'], $values['moved_in_at']) && $values['moved_in_at'] < $values['arrival_date']) {
            throw ValidationException::withMessages(['value' => 'The actual move-in is before the recorded arrival for this stay. Please check those dates.']);
        }
    }
}
