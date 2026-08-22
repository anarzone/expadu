<?php

namespace App\Bureaucracy\Cases;

use App\Bureaucracy\Facts\FactRegistry;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyFactConflict;
use App\Models\Task;
use App\Profile\Applicability;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Which unanswered questions would still change what this user is shown.
 *
 * QuestionSelector drives the case plan, so it only ever looks at authoritative
 * rules — a legal claim must never rest on an unreviewed one. That is correct
 * for the plan and wrong for the prompt: a fact can gate a branch that is
 * published but not yet approved, and the user is then never asked for it at
 * all. Measured on the current catalogue, `entry_mode` — the single
 * most-referenced fact in the whole thing — is invisible to standard
 * employees, students and freelancers for exactly this reason.
 *
 * So this sweeps every PUBLISHED rule and reports the registered facts those
 * rules are waiting on. It is deliberately a weaker surface than the plan: it
 * asks questions, it never asserts guidance, and nothing it returns reaches
 * CasePlanComposer.
 */
final class PendingAnswers
{
    public function __construct(
        private FactRegistry $factRegistry,
        private CaseAttributes $caseAttributes,
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
            ->where('status', 'unresolved')
            ->pluck('fact_key')
            ->all();

        $pending = [];

        foreach ($this->publishedRules() as $task) {
            if (Applicability::evaluate($task->applies_if, $attributes) !== Applicability::Unknown) {
                continue;
            }

            foreach (Applicability::unknownAttributes($task->applies_if, $attributes) as $key) {
                if (in_array($key, $conflicted, true) || ! $this->isRegisteredFact($key)) {
                    continue;
                }

                $pending[$key] = true;
            }
        }

        $keys = array_keys($pending);

        usort($keys, function (string $left, string $right): int {
            $byPriority = $this->priority($right) <=> $this->priority($left);

            return $byPriority !== 0 ? $byPriority : $left <=> $right;
        });

        return $keys;
    }

    /**
     * @return Collection<int, Task>
     */
    private function publishedRules(): Collection
    {
        return Task::query()
            ->where('is_published', true)
            ->whereNotNull('applies_if')
            ->orderBy('key')
            ->get();
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
