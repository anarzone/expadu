<?php

namespace App\Bureaucracy\Cases;

use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyPlanSnapshot;
use App\Models\Task;
use App\Models\UserTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use JsonException;

final class PlanSnapshotStore
{
    public function __construct(
        private CaseMatcher $caseMatcher,
        private CasePlanComposer $planComposer,
    ) {}

    /**
     * @throws JsonException
     */
    public function store(
        BureaucracyCase $case,
        bool $explicitReassessment = false,
    ): BureaucracyPlanSnapshot {
        return DB::transaction(function () use ($case, $explicitReassessment): BureaucracyPlanSnapshot {
            $lockedCase = BureaucracyCase::query()
                ->whereKey($case->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $result = $this->caseMatcher->match($lockedCase);
            $sections = $this->planComposer->compose($lockedCase, $result);
            $signature = $this->signature($lockedCase, $result);
            $activeSnapshots = BureaucracyPlanSnapshot::query()
                ->where('case_id', $lockedCase->getKey())
                ->whereNull('superseded_at')
                ->orderByDesc('generated_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();
            $active = $activeSnapshots->first();

            if ($active instanceof BureaucracyPlanSnapshot
                && ! $explicitReassessment
                && $active->fact_version === $lockedCase->fact_version
                && hash_equals($active->rules_hash, $signature)
                && ($active->reassessment_at === null || $active->reassessment_at->isFuture())) {
                $lockedCase->update(['last_assessed_at' => now()]);

                return $active;
            }

            foreach ($activeSnapshots as $snapshot) {
                $snapshot->update(['superseded_at' => now()]);
            }

            $snapshot = BureaucracyPlanSnapshot::query()->create([
                'case_id' => $lockedCase->getKey(),
                'fact_version' => $lockedCase->fact_version,
                'rules_hash' => $signature,
                'rule_versions' => $result->ruleVersions,
                'coverage_state' => $result->coverageState->value,
                'sections' => $sections,
                'unresolved_facts' => $result->missingFactKeys,
                'reassessment_at' => $this->nextReassessmentAt($lockedCase),
                'generated_at' => now(),
            ]);

            $lockedCase->update(['last_assessed_at' => now()]);

            return $snapshot;
        });
    }

    /**
     * @throws JsonException
     */
    private function signature(BureaucracyCase $case, CaseMatchResult $result): string
    {
        return hash('sha256', json_encode([
            'rule_versions' => $result->ruleVersions,
            'task_state' => $this->taskState($case, $result),
            'date_boundary' => today()->toDateString(),
            'coverage_state' => $result->coverageState->value,
            'matched_rule_keys' => $result->matchedRuleKeys,
            'missing_fact_keys' => $result->missingFactKeys,
            'conflict_pairs' => $result->conflictPairs,
            'safe_rule_keys' => $result->safeRuleKeys,
            'universal_rule_keys' => $result->universalRuleKeys,
            'unknown_rule_keys' => $result->unknownRuleKeys,
        ], JSON_THROW_ON_ERROR));
    }

    private function nextReassessmentAt(BureaucracyCase $case): CarbonImmutable
    {
        $nextBoundary = CarbonImmutable::tomorrow(config('app.timezone'));
        $nextFactReconfirmation = BureaucracyCaseFact::query()
            ->where('case_id', $case->getKey())
            ->where('state', 'confirmed')
            ->whereNull('superseded_at')
            ->where('reconfirm_at', '>', now())
            ->min('reconfirm_at');

        if ($nextFactReconfirmation === null) {
            return $nextBoundary;
        }

        $factBoundary = CarbonImmutable::parse($nextFactReconfirmation, config('app.timezone'));

        return $factBoundary->lessThan($nextBoundary) ? $factBoundary : $nextBoundary;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function taskState(BureaucracyCase $case, CaseMatchResult $result): array
    {
        $tasks = Task::query()
            ->whereIn('key', array_keys($result->ruleVersions))
            ->get(['id', 'key', 'depends_on']);
        $relevantKeys = $tasks->pluck('key')
            ->merge($tasks->flatMap(fn (Task $task): array => $task->depends_on ?? []))
            ->filter(fn (mixed $key): bool => is_string($key))
            ->unique()
            ->values();
        $relevantTaskIds = Task::query()
            ->whereIn('key', $relevantKeys)
            ->pluck('id');

        return UserTask::query()
            ->where('user_id', $case->user_id)
            ->whereIn('task_id', $relevantTaskIds)
            ->with('task:id,key')
            ->orderBy('task_id')
            ->get()
            ->map(fn (UserTask $userTask): array => [
                'key' => $userTask->task?->key,
                'status' => $userTask->status->value,
                'is_applicable' => $userTask->is_applicable,
                'completed_at' => $userTask->completed_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
