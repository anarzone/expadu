<?php

namespace App\Console\Commands\Bureaucracy;

use App\ContextEngine\Evaluators\BureaucracyEvaluator;
use App\Models\Task;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Scheduled daily at 09:00 Europe/Berlin. Iterates onboarded users, materialises
 * any missing user_task rows for their situation, and feeds each task through
 * BureaucracyEvaluator. The evaluator decides whether to emit a ScoredAction.
 *
 * Push dedup is handled inside the evaluator (first-crossing per tier, 30-day
 * Redis TTL) so this command can safely re-run without spamming users.
 */
class RemindCommand extends Command
{
    protected $signature = 'bureaucracy:remind {--user= : Only process this user id} {--dry-run : Skip ActionBus writes}';

    protected $description = 'Score bureaucracy tasks for onboarded users and surface urgent ones via the action bus';

    public function handle(BureaucracyEvaluator $evaluator): int
    {
        $usersProcessed = 0;
        $tasksEvaluated = 0;

        $query = User::query()->whereNotNull('onboarded_at')->whereNotNull('situation');
        if ($specific = $this->option('user')) {
            $query->where('id', (int) $specific);
        }

        $query->chunkById(100, function ($users) use ($evaluator, &$usersProcessed, &$tasksEvaluated): void {
            foreach ($users as $user) {
                $this->ensureUserTasks($user);
                $usersProcessed++;

                $userTasks = $user->userTasks()->with('task')->open()->get();
                foreach ($userTasks as $userTask) {
                    if ($this->option('dry-run')) {
                        $tasksEvaluated++;

                        continue;
                    }
                    $evaluator->evaluate($user, $userTask);
                    $tasksEvaluated++;
                }
            }
        });

        $this->info("Done. users={$usersProcessed} user_tasks_evaluated={$tasksEvaluated}");

        return self::SUCCESS;
    }

    /**
     * Materialise UserTask rows for every Task that matches the user's
     * situation but doesn't yet have a corresponding pivot. Idempotent.
     */
    private function ensureUserTasks(User $user): void
    {
        $situation = $user->situation?->value;
        if (! $situation) {
            return;
        }

        $existingTaskIds = $user->userTasks()->pluck('task_id')->all();

        Task::query()
            ->whereJsonContains('situation', $situation)
            ->whereNotIn('id', $existingTaskIds)
            ->get()
            ->each(fn ($task) => $user->userTasks()->create([
                'task_id' => $task->id,
            ]));
    }
}
