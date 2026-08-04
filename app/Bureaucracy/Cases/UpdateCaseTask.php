<?php

namespace App\Bureaucracy\Cases;

use App\Bureaucracy\RuleSourcePolicy;
use App\Enums\TaskStatus;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyPlanSnapshot;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class UpdateCaseTask
{
    /** @var list<string> */
    private const ActionableSections = [
        'current_status',
        'do_now',
        'next',
        'coming_up',
        'options',
        'waiting',
    ];

    public function __construct(private RuleSourcePolicy $sourcePolicy) {}

    public function update(User $user, Task $task, TaskStatus $status): UserTask
    {
        return DB::transaction(function () use ($user, $task, $status): UserTask {
            $case = BureaucracyCase::query()
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if (! $case instanceof BureaucracyCase) {
                throw new AuthorizationException;
            }

            $snapshot = BureaucracyPlanSnapshot::query()
                ->where('case_id', $case->getKey())
                ->whereNull('superseded_at')
                ->orderByDesc('generated_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $snapshot instanceof BureaucracyPlanSnapshot
                || (int) $snapshot->fact_version !== (int) $case->fact_version
                || $snapshot->reassessment_at?->isPast()
                || ($snapshot->rule_versions[$task->key] ?? null) !== $task->content_version
                || ! $this->isCurrentlyAuthoritative($task)) {
                throw new AuthorizationException;
            }

            $section = $this->snapshotSection($snapshot, $task->key);
            $existingUserTask = UserTask::query()
                ->where('user_id', $user->getKey())
                ->where('task_id', $task->getKey())
                ->first();

            if ($section === null
                || $task->isInfo()
                || ($section === 'waiting' && $existingUserTask?->status !== TaskStatus::Submitted)) {
                throw new AuthorizationException;
            }

            $userTask = $existingUserTask ?? UserTask::query()->firstOrCreate([
                'user_id' => $user->getKey(),
                'task_id' => $task->getKey(),
            ]);
            $userTask->is_applicable = true;

            if ($status === TaskStatus::Done) {
                $userTask->save();
                $userTask->markDone();
                $snapshot->update(['superseded_at' => now()]);

                return $userTask->fresh();
            }

            $userTask->status = $status;

            if ($userTask->completed_at !== null) {
                $userTask->completed_at = null;
                $userTask->next_due_at = null;
            }

            $userTask->save();
            $snapshot->update(['superseded_at' => now()]);

            return $userTask->fresh();
        });
    }

    /**
     * @param  list<string>  $documentsChecked
     */
    public function updateDocuments(User $user, Task $task, array $documentsChecked): UserTask
    {
        return DB::transaction(function () use ($user, $task, $documentsChecked): UserTask {
            $case = BureaucracyCase::query()
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if (! $case instanceof BureaucracyCase) {
                throw new AuthorizationException;
            }

            $snapshot = BureaucracyPlanSnapshot::query()
                ->where('case_id', $case->getKey())
                ->whereNull('superseded_at')
                ->orderByDesc('generated_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $snapshot instanceof BureaucracyPlanSnapshot
                || (int) $snapshot->fact_version !== (int) $case->fact_version
                || $snapshot->reassessment_at?->isPast()
                || ($snapshot->rule_versions[$task->key] ?? null) !== $task->content_version
                || $this->snapshotSection($snapshot, $task->key) === null
                || $task->isInfo()
                || ! $this->isCurrentlyAuthoritative($task)) {
                throw new AuthorizationException;
            }

            $knownDocuments = collect($task->documents_required ?? [])
                ->map(fn (mixed $document): mixed => is_array($document) ? ($document['label'] ?? null) : $document)
                ->filter(fn (mixed $document): bool => is_string($document) && $document !== '')
                ->values()
                ->all();
            $safeDocuments = array_values(array_intersect($documentsChecked, $knownDocuments));
            $userTask = UserTask::query()->firstOrCreate([
                'user_id' => $user->getKey(),
                'task_id' => $task->getKey(),
            ]);

            if (! $userTask->is_applicable) {
                $userTask->status = TaskStatus::NotStarted;
                $userTask->completed_at = null;
                $userTask->next_due_at = null;
            }

            $userTask->update([
                'is_applicable' => true,
                'documents_checked' => $safeDocuments,
            ]);

            return $userTask->fresh();
        });
    }

    private function snapshotSection(BureaucracyPlanSnapshot $snapshot, ?string $taskKey): ?string
    {
        if (! is_string($taskKey) || $taskKey === '') {
            return null;
        }

        foreach (Arr::only($snapshot->sections ?? [], self::ActionableSections) as $section => $items) {
            if (collect($items)->contains(
                fn (mixed $item): bool => is_array($item) && ($item['key'] ?? null) === $taskKey,
            )) {
                return $section;
            }
        }

        return null;
    }

    private function isCurrentlyAuthoritative(Task $task): bool
    {
        return Task::query()
            ->authoritative()
            ->whereKey($task->getKey())
            ->exists()
            && $this->sourcePolicy->persistedErrors($task) === [];
    }
}
