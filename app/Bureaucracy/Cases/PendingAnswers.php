<?php

namespace App\Bureaucracy\Cases;

use App\Bureaucracy\Facts\FactRegistry;
use App\Bureaucracy\Questions\OrientationQuestions;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyFactConflict;
use DomainException;

/**
 * Relevant approved-rule dependencies plus explicitly reviewed basic orientation.
 * Missing legal coverage must never cause an interview with no usable outcome.
 */
final class PendingAnswers
{
    public function __construct(
        private FactRegistry $factRegistry,
        private CaseAttributes $caseAttributes,
        private CaseMatcher $matcher,
        private OrientationQuestions $orientation,
    ) {}

    /**
     * Registered fact keys with no usable answer, ranked by the registry's own
     * priority. Facts already in conflict are left out — resolving a conflict
     * is a different conversation from answering a question.
     *
     * @return list<string>
     */
    public function forCase(BureaucracyCase $case): array
    {
        $attributes = $this->caseAttributes->for($case);

        $conflicted = BureaucracyFactConflict::query()
            ->where('case_id', $case->getKey())
            ->actionable()
            ->pluck('fact_key')
            ->all();

        $pending = [];

        $candidates = [
            ...$this->matcher->match($case)->missingFactKeys,
            ...$this->orientation->missingKeys($attributes),
        ];

        foreach ($candidates as $key) {
            if (in_array($key, $conflicted, true) || ! $this->isRegisteredFact($key)) {
                continue;
            }

            $pending[$key] = true;
        }

        $keys = array_keys($pending);

        usort($keys, function (string $left, string $right): int {
            $byPriority = $this->priority($right) <=> $this->priority($left);

            return $byPriority !== 0 ? $byPriority : $left <=> $right;
        });

        return $keys;
    }

    private function isRegisteredFact(string $key): bool
    {
        try {
            $this->factRegistry->definition($key);

            return true;
        } catch (DomainException) {
            return false;
        }
    }

    private function priority(string $key): int
    {
        try {
            return $this->factRegistry->definition($key)->priority;
        } catch (DomainException) {
            return 0;
        }
    }
}
