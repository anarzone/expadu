<?php

namespace App\Bureaucracy\Cases;

use App\Bureaucracy\Facts\CaseFactStore;
use App\Bureaucracy\Facts\FactRegistry;
use App\Bureaucracy\RuleSourcePolicy;
use App\Enums\BureaucracyCoverageState;
use App\Enums\TaskStatus;
use App\Models\BureaucracyCase;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use Illuminate\Support\Collection;

final class CasePlanComposer
{
    /** @var list<string> */
    private const SectionKeys = [
        'current_status',
        'do_now',
        'next',
        'coming_up',
        'options',
        'waiting',
        'information_needed',
        'not_covered',
    ];

    public function __construct(
        private FactRegistry $factRegistry,
        private CaseFactStore $factStore,
        private RuleSourcePolicy $sourcePolicy,
    ) {}

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function compose(BureaucracyCase $case, CaseMatchResult $result): array
    {
        $sections = array_fill_keys(self::SectionKeys, []);
        $user = $case->user()->firstOrFail();
        $visibleKeys = array_values(array_unique([
            ...$result->safeRuleKeys,
            ...$result->universalRuleKeys,
        ]));
        $relevantKeys = array_values(array_unique([...$visibleKeys, ...$result->unknownRuleKeys]));
        $tasks = $this->authoritativeTasks($relevantKeys);
        $dependencyKeys = $tasks
            ->flatMap(fn (Task $task): array => $task->depends_on ?? [])
            ->filter(fn (mixed $key): bool => is_string($key));
        $stateTaskIds = Task::query()
            ->whereIn('key', collect($relevantKeys)->merge($dependencyKeys)->unique())
            ->pluck('id');
        $userTasks = UserTask::query()
            ->where('user_id', $case->user_id)
            ->whereIn('task_id', $stateTaskIds)
            ->with('task:id,key')
            ->get()
            ->keyBy('task_id');
        $doneKeys = $userTasks
            ->filter(fn (UserTask $userTask): bool => $userTask->status === TaskStatus::Done && $userTask->is_applicable)
            ->map(fn (UserTask $userTask): ?string => $userTask->task?->key)
            ->filter()
            ->values();

        foreach ($visibleKeys as $key) {
            $task = $tasks->get($key);

            if (! $task instanceof Task || ($result->ruleVersions[$key] ?? null) !== $task->content_version) {
                continue;
            }

            $userTask = $userTasks->get($task->id);
            $section = $this->sectionFor($task, $userTask, $doneKeys);
            $sections[$section][] = $this->taskItem($case, $user, $task);
        }

        foreach ($result->unknownRuleKeys as $key) {
            $task = $tasks->get($key);

            if (! $task instanceof Task || ($result->ruleVersions[$key] ?? null) !== $task->content_version) {
                continue;
            }

            $missingFacts = $result->missingFactsByRule[$key] ?? [];
            $questions = [];

            foreach ($missingFacts as $factKey) {
                $definition = $this->factRegistry->definition($factKey);
                $questions[] = [
                    'fact_key' => $factKey,
                    'question' => $definition->question,
                    'why' => $definition->why,
                ];
            }

            $sections['information_needed'][] = [
                ...$this->taskItem($case, $user, $task),
                'missing_fact_keys' => $missingFacts,
                'questions' => $questions,
            ];
        }

        if ($result->coverageState === BureaucracyCoverageState::NotCovered) {
            $sections['not_covered'][] = [
                'kind' => 'coverage_notice',
                'coverage_state' => BureaucracyCoverageState::NotCovered->value,
            ];
        }

        foreach ($sections as &$items) {
            usort($items, fn (array $left, array $right): int => ($left['key'] ?? '') <=> ($right['key'] ?? ''));
        }
        unset($items);

        return $sections;
    }

    /**
     * @param  list<string>  $keys
     * @return Collection<string, Task>
     */
    private function authoritativeTasks(array $keys): Collection
    {
        if ($keys === []) {
            return collect();
        }

        return Task::query()
            ->authoritative()
            ->whereIn('key', $keys)
            ->get()
            ->filter(fn (Task $task): bool => $this->sourcePolicy->persistedErrors($task) === [])
            ->keyBy('key');
    }

    /**
     * @param  Collection<int, string>  $doneKeys
     */
    private function sectionFor(Task $task, ?UserTask $userTask, Collection $doneKeys): string
    {
        if ($userTask?->status === TaskStatus::Done) {
            return 'current_status';
        }

        if ($userTask?->status === TaskStatus::Submitted || ! $this->dependenciesSatisfied($task, $doneKeys)) {
            return 'waiting';
        }

        if ($task->isInfo()) {
            return match ($task->phase) {
                'ongoing' => 'coming_up',
                'waiting' => 'waiting',
                default => 'options',
            };
        }

        return match ($task->phase) {
            'current_status' => 'current_status',
            'arrival', 'first_14_days', 'first_30_days', 'first_weeks' => 'do_now',
            'ongoing' => 'coming_up',
            'options' => 'options',
            default => 'next',
        };
    }

    /**
     * @param  Collection<int, string>  $doneKeys
     */
    private function dependenciesSatisfied(Task $task, Collection $doneKeys): bool
    {
        foreach ($task->depends_on ?? [] as $dependency) {
            if (! is_string($dependency) || ! $doneKeys->contains($dependency)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function taskItem(BureaucracyCase $case, User $user, Task $task): array
    {
        $attributes = null;

        if ($task->deadline_type?->value === 'fact_date' && is_string($task->deadline_fact_key)) {
            $fact = $this->factStore->confirmedFact($case, $task->deadline_fact_key);
            $attributes = [$task->deadline_fact_key => $fact?->value];
        }

        return [
            'key' => $task->key,
            'content_version' => $task->content_version,
            'title' => $task->title,
            'description' => $task->description,
            'type' => $task->type,
            'phase' => $task->phase,
            'urgency' => $task->urgency?->value,
            'depends_on' => $task->depends_on ?? [],
            'deadline' => $task->computeDeadlineFor($user, $attributes)?->toDateString(),
            'documents_required' => $task->documents_required ?? [],
            'decision_options' => $task->decision_options ?? [],
            'how_to_steps' => $task->how_to_steps ?? [],
            'links' => $task->links ?? [],
            'legal_sources' => $task->legal_sources ?? [],
            'verified_at' => $task->verified_at?->toDateString(),
            'high_impact' => $task->deadline_type?->value !== 'none'
                || in_array($task->urgency?->value, ['critical', 'high'], true),
        ];
    }
}
