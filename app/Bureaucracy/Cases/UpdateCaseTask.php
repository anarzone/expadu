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
                || ! $this->snapshotContains($snapshot, $task->key)
                || ! $this->isCurrentlyAuthoritative($task)) {
                throw new AuthorizationException;
            }

            $userTask = UserTask::query()->firstOrCreate([
                'user_id' => $user->getKey(),
                'task_id' => $task->getKey(),
            ]);

            if ($status === TaskStatus::Done) {
                $userTask->markDone();

                return $userTask->fresh();
            }

            $userTask->status = $status;

            if ($userTask->completed_at !== null) {
                $userTask->completed_at = null;
                $userTask->next_due_at = null;
            }

            $userTask->save();

            return $userTask->fresh();
        });
    }

    private function snapshotContains(BureaucracyPlanSnapshot $snapshot, ?string $taskKey): bool
    {
        if (! is_string($taskKey) || $taskKey === '') {
            return false;
        }

        return collect(Arr::only($snapshot->sections ?? [], self::ActionableSections))
            ->flatten(1)
            ->contains(fn (mixed $item): bool => is_array($item) && ($item['key'] ?? null) === $taskKey);
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
