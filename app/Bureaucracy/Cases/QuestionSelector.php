<?php

namespace App\Bureaucracy\Cases;

use App\Bureaucracy\Facts\FactRegistry;
use App\Bureaucracy\RuleSourcePolicy;
use App\Enums\TaskStatus;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseQuestion;
use App\Models\Task;
use App\Models\UserTask;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class QuestionSelector
{
    private const MaxQuestionsPerCase = 12;

    private const MaxAttemptsPerFact = 3;

    public function __construct(
        private FactRegistry $factRegistry,
        private RuleSourcePolicy $sourcePolicy,
    ) {}

    /**
     * @return list<string>
     */
    public function rankedFactKeys(BureaucracyCase $case, CaseMatchResult $result): array
    {
        $definitions = collect($result->missingFactKeys)
            ->mapWithKeys(function (string $key): array {
                try {
                    return [$key => $this->factRegistry->definition($key)];
                } catch (DomainException) {
                    return [];
                }
            });

        if ($definitions->isEmpty() || $result->unknownRuleKeys === []) {
            return [];
        }

        $unknownTasks = Task::query()
            ->authoritative()
            ->whereIn('key', $result->unknownRuleKeys)
            ->get()
            ->filter(fn (Task $task): bool => $this->sourcePolicy->persistedErrors($task) === [])
            ->keyBy('key');
        $allTasks = Task::query()
            ->authoritative()
            ->get()
            ->filter(fn (Task $task): bool => $this->sourcePolicy->persistedErrors($task) === []);
        $doneKeys = $this->doneTaskKeys($case);
        $ranked = [];

        foreach ($definitions as $key => $definition) {
            $gatingTasks = $unknownTasks->filter(
                fn (Task $task): bool => in_array($key, $result->missingFactsByRule[$task->key] ?? [], true),
            );

            if ($gatingTasks->isEmpty()) {
                continue;
            }

            $ranked[] = [
                'key' => $key,
                'risk' => $gatingTasks->max(fn (Task $task): int => $this->urgencyWeight($task)) ?? 0,
                'branches' => $gatingTasks->count(),
                'next_actions' => $gatingTasks
                    ->filter(fn (Task $task): bool => $this->unlocksNextAction($task, $doneKeys))
                    ->count(),
                'sensitivity' => $definition->sensitivity === 'normal' ? 1 : 0,
                'reuse' => $allTasks->filter(fn (Task $task): bool => $this->referencesFact($task, $key))->count(),
                'priority' => $definition->priority,
            ];
        }

        usort($ranked, function (array $left, array $right): int {
            foreach (['risk', 'branches', 'next_actions', 'sensitivity', 'reuse', 'priority'] as $metric) {
                $comparison = $right[$metric] <=> $left[$metric];

                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return $left['key'] <=> $right['key'];
        });

        return array_column($ranked, 'key');
    }

    public function select(BureaucracyCase $case, CaseMatchResult $result): ?BureaucracyCaseQuestion
    {
        return DB::transaction(function () use ($case, $result): ?BureaucracyCaseQuestion {
            $lockedCase = BureaucracyCase::query()
                ->whereKey($case->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $rankedKeys = $this->rankedFactKeys($lockedCase, $result);

            if ($rankedKeys === []) {
                return null;
            }

            $unanswered = BureaucracyCaseQuestion::query()
                ->where('case_id', $lockedCase->getKey())
                ->whereNull('answered_at')
                ->whereIn('fact_key', $rankedKeys)
                ->orderByDesc('asked_at')
                ->lockForUpdate()
                ->get()
                ->sortBy(fn (BureaucracyCaseQuestion $question): int => array_search($question->fact_key, $rankedKeys, true))
                ->first();

            if ($unanswered instanceof BureaucracyCaseQuestion) {
                return $unanswered;
            }

            $questionCount = BureaucracyCaseQuestion::query()
                ->where('case_id', $lockedCase->getKey())
                ->count();

            if ($questionCount >= self::MaxQuestionsPerCase) {
                return null;
            }

            foreach ($rankedKeys as $factKey) {
                $attempt = (int) BureaucracyCaseQuestion::query()
                    ->where('case_id', $lockedCase->getKey())
                    ->where('fact_key', $factKey)
                    ->max('attempt');

                if ($attempt >= self::MaxAttemptsPerFact) {
                    continue;
                }

                return BureaucracyCaseQuestion::query()->create([
                    'case_id' => $lockedCase->getKey(),
                    'fact_key' => $factKey,
                    'attempt' => $attempt + 1,
                    'asked_at' => now(),
                ]);
            }

            return null;
        });
    }

    /**
     * @return Collection<int, string>
     */
    private function doneTaskKeys(BureaucracyCase $case): Collection
    {
        return UserTask::query()
            ->where('user_id', $case->user_id)
            ->where('status', TaskStatus::Done->value)
            ->where('is_applicable', true)
            ->with('task:id,key')
            ->get()
            ->pluck('task.key')
            ->filter(fn (mixed $key): bool => is_string($key))
            ->values();
    }

    /**
     * @param  Collection<int, string>  $doneKeys
     */
    private function unlocksNextAction(Task $task, Collection $doneKeys): bool
    {
        if ($task->isInfo()) {
            return false;
        }

        foreach ($task->depends_on ?? [] as $dependency) {
            if (! is_string($dependency) || ! $doneKeys->contains($dependency)) {
                return false;
            }
        }

        return true;
    }

    private function urgencyWeight(Task $task): int
    {
        return match ($task->urgency?->value) {
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }

    private function referencesFact(Task $task, string $factKey): bool
    {
        foreach ($task->applies_if ?? [] as $group) {
            if (is_array($group) && array_key_exists($factKey, $group)) {
                return true;
            }
        }

        return false;
    }
}
