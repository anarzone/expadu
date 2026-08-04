<?php

namespace App\Bureaucracy\Cases;

use App\Bureaucracy\Facts\FactRegistry;
use App\Bureaucracy\RuleSourcePolicy;
use App\Enums\BureaucracyCoverageState;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyFactConflict;
use App\Models\Task;
use App\Profile\Applicability;
use App\Profile\ProfileEngine;
use DomainException;
use Illuminate\Support\Collection;

final class CaseMatcher
{
    public function __construct(
        private ProfileEngine $profileEngine,
        private FactRegistry $factRegistry,
        private RuleSourcePolicy $sourcePolicy,
    ) {}

    public function match(BureaucracyCase $case): CaseMatchResult
    {
        $attributes = $this->attributes($case);
        $tasks = $this->authoritativeTasks();
        $matched = [];
        $universal = [];
        $unknown = [];
        $missingFactsByRule = [];
        $ruleVersions = [];

        foreach ($tasks as $task) {
            $ruleVersions[$task->key] = $task->content_version;
            $verdict = Applicability::evaluate($task->applies_if, $attributes);

            if ($task->coverage_scope === 'universal') {
                if ($verdict === Applicability::Yes) {
                    $universal[] = $task->key;
                }

                continue;
            }

            if ($verdict === Applicability::Yes) {
                $matched[] = $task->key;

                continue;
            }

            if ($verdict !== Applicability::Unknown) {
                continue;
            }

            $missing = Applicability::unknownAttributes($task->applies_if, $attributes);
            $missing = array_values(array_filter(
                $missing,
                fn (string $key): bool => $this->isRegisteredFact($key),
            ));

            if ($missing === []) {
                continue;
            }

            sort($missing, SORT_STRING);
            $unknown[] = $task->key;
            $missingFactsByRule[$task->key] = $missing;
        }

        sort($matched, SORT_STRING);
        sort($universal, SORT_STRING);
        sort($unknown, SORT_STRING);
        ksort($ruleVersions, SORT_STRING);
        ksort($missingFactsByRule, SORT_STRING);

        $conflictPairs = $this->conflictPairs($tasks, $matched);
        $conflictingKeys = array_values(array_unique(array_merge(...$conflictPairs ?: [[]])));
        $safe = array_values(array_diff($matched, $conflictingKeys));
        sort($safe, SORT_STRING);

        $missingFacts = array_values(array_unique(array_merge(...array_values($missingFactsByRule) ?: [[]])));
        sort($missingFacts, SORT_STRING);

        $hasUnresolvedFactConflict = BureaucracyFactConflict::query()
            ->where('case_id', $case->getKey())
            ->where('status', 'unresolved')
            ->exists();
        $coverageState = match (true) {
            $hasUnresolvedFactConflict => BureaucracyCoverageState::Conflict,
            $conflictPairs !== [] => BureaucracyCoverageState::Conflict,
            $unknown !== [] => BureaucracyCoverageState::NeedsInformation,
            $matched !== [] => BureaucracyCoverageState::Matched,
            default => BureaucracyCoverageState::NotCovered,
        };

        return new CaseMatchResult(
            coverageState: $coverageState,
            matchedRuleKeys: $matched,
            ruleVersions: $ruleVersions,
            missingFactKeys: $missingFacts,
            conflictPairs: $conflictPairs,
            safeRuleKeys: $safe,
            universalRuleKeys: $universal,
            unknownRuleKeys: $unknown,
            missingFactsByRule: $missingFactsByRule,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(BureaucracyCase $case): array
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

    /**
     * @return Collection<int, Task>
     */
    private function authoritativeTasks(): Collection
    {
        return Task::query()
            ->authoritative()
            ->whereNotNull('key')
            ->where('key', '<>', '')
            ->orderBy('key')
            ->get()
            ->filter(fn (Task $task): bool => $this->sourcePolicy->persistedErrors($task) === [])
            ->filter(fn (Task $task): bool => $this->hasValidConditions($task))
            ->values();
    }

    private function hasValidConditions(Task $task): bool
    {
        if (! in_array($task->coverage_scope, ['case', 'universal'], true)) {
            return false;
        }

        if ($task->applies_if === null || $task->applies_if === []) {
            return true;
        }

        if (! array_is_list($task->applies_if)) {
            return false;
        }

        try {
            foreach ($task->applies_if as $group) {
                if (! is_array($group) || array_is_list($group) || $group === []) {
                    return false;
                }

                foreach ($group as $key => $condition) {
                    if (! is_string($key)) {
                        return false;
                    }

                    try {
                        $this->factRegistry->validateConditionOperand($key, $condition);
                    } catch (DomainException $exception) {
                        if (! $this->isTrustedBranchPredicate($key, $condition)) {
                            throw $exception;
                        }
                    }
                }
            }
        } catch (DomainException) {
            return false;
        }

        return true;
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

    private function isTrustedBranchPredicate(string $key, mixed $condition): bool
    {
        foreach (ProfileEngine::BRANCH_PREDICATES as $predicate) {
            if (array_key_exists($key, $predicate) && $predicate[$key] === $condition) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @param  list<string>  $matchedKeys
     * @return list<array{0: string, 1: string}>
     */
    private function conflictPairs(Collection $tasks, array $matchedKeys): array
    {
        $matched = array_fill_keys($matchedKeys, true);
        $pairs = [];

        foreach ($tasks as $task) {
            if (! isset($matched[$task->key])) {
                continue;
            }

            foreach ($task->conflicts_with ?? [] as $conflictingKey) {
                if (! is_string($conflictingKey) || ! isset($matched[$conflictingKey])) {
                    continue;
                }

                $pair = [$task->key, $conflictingKey];
                sort($pair, SORT_STRING);
                $pairs[implode('\0', $pair)] = $pair;
            }
        }

        ksort($pairs, SORT_STRING);

        return array_values($pairs);
    }
}
