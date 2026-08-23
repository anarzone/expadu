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
    /**
     * Bump when the composer files the same rules into different sections.
     *
     * The snapshot signature covers the CASE — facts, matched rules, dates —
     * so a purely presentational change never invalidated it, and a stored
     * plan kept its old layout until the user's situation happened to change.
     * That is how "Options you may qualify for" went on showing a universal
     * caveat after the routing for it was fixed.
     */
    public const LayoutVersion = '2026-08-23.good-to-know';

    /** @var list<string> */
    private const SectionKeys = [
        'current_status',
        'do_now',
        'next',
        'coming_up',
        'options',
        'good_to_know',
        'waiting',
        'information_needed',
        'opens_when',
        'not_covered',
    ];

    public function __construct(
        private NearMissRules $nearMissRules,
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
        $user = $case->relationLoaded('user')
            ? $case->user
            : $case->user()->firstOrFail();
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

        // One card per unanswered QUESTION, not per blocked rule. Two rules can
        // wait on the same fact — a family-reunification renewal has two that
        // both hinge on whether the household continues — and a card each meant
        // the page asked the identical question twice under a heading that
        // named neither step.
        $blockedByFact = [];

        foreach ($result->unknownRuleKeys as $key) {
            $task = $tasks->get($key);

            if (! $task instanceof Task || ($result->ruleVersions[$key] ?? null) !== $task->content_version) {
                continue;
            }

            foreach ($result->missingFactsByRule[$key] ?? [] as $factKey) {
                $blockedByFact[$factKey][] = $task->title;
            }
        }

        foreach ($blockedByFact as $factKey => $titles) {
            $definition = $this->factRegistry->definition($factKey);
            $titles = array_values(array_unique(array_filter($titles)));
            sort($titles, SORT_STRING);

            $sections['information_needed'][] = [
                'kind' => 'information_needed',
                'fact_key' => $factKey,
                // Kept as a list so the card renderer stays unchanged for any
                // caller that still reads it.
                'questions' => [[
                    'question' => $definition->question,
                    'why' => $definition->why,
                ]],
                // What the answer would actually unblock, so the card can say
                // which step it is holding rather than "a possible step".
                'unlocks' => $titles,
            ];
        }

        // Routes that do not apply YET, with the one answer that opens them.
        // A hidden rule is invisible for a reason the user cannot see.
        foreach ($this->nearMissRules->forCase($case) as $row) {
            $sections['opens_when'][] = [
                'kind' => 'opens_when',
                'key' => $row['key'],
                'content_version' => $row['content_version'],
                'title' => $row['title'],
                'opens_when' => $row['opens_when'],
            ];
        }

        if (in_array($result->coverageState, [
            BureaucracyCoverageState::NotCovered,
            BureaucracyCoverageState::Conflict,
        ], true)) {
            $sections['not_covered'][] = [
                'kind' => 'coverage_notice',
                'coverage_state' => $result->coverageState->value,
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
        if ($userTask instanceof UserTask && ! $userTask->is_applicable) {
            $userTask = null;
        }

        if ($userTask?->status === TaskStatus::Done) {
            return 'current_status';
        }

        if ($userTask?->status === TaskStatus::Submitted || ! $this->dependenciesSatisfied($task, $doneKeys)) {
            return 'waiting';
        }

        if ($task->isInfo()) {
            // A universal card applies to everyone regardless of case, so by
            // definition it is not a route THIS person might qualify for.
            // Everything info-shaped used to fall through to `options`, which
            // put "we may not have a reviewed rule for your title" under a
            // heading promising "possible routes to compare".
            if ($task->coverage_scope === 'universal') {
                return 'good_to_know';
            }

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
