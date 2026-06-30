<?php

namespace App\Console\Commands\Bureaucracy;

use App\Bureaucracy\PathGenerator;
use App\ContextEngine\Evaluators\BureaucracyEvaluator;
use App\ContextEngine\Evaluators\PermanentResidencyEvaluator;
use App\Models\User;
use App\Profile\Applicability;
use Illuminate\Console\Command;

/**
 * Scheduled daily at 09:00 Europe/Berlin. Iterates onboarded users, materialises
 * any missing user_task rows for their situation, and feeds each task through
 * BureaucracyEvaluator. The evaluator decides whether to emit a ScoredAction.
 *
 * Each user also gets one PermanentResidencyEvaluator pass — the good-news
 * counterpart that surfaces NE eligibility into the Alerts "Good news" lane.
 *
 * Dedup is handled inside the evaluators (deadline push first-crossing per tier;
 * residency announce-once per threshold) so this command can safely re-run
 * without spamming users.
 */
class RemindCommand extends Command
{
    protected $signature = 'bureaucracy:remind {--user= : Only process this user id} {--dry-run : Skip ActionBus writes}';

    protected $description = 'Score bureaucracy tasks for onboarded users and surface urgent ones via the action bus';

    public function handle(BureaucracyEvaluator $evaluator, PermanentResidencyEvaluator $residencyEvaluator, PathGenerator $generator): int
    {
        $usersProcessed = 0;
        $tasksEvaluated = 0;

        $query = User::query()->whereNotNull('onboarded_at')->whereNotNull('situation');
        if ($specific = $this->option('user')) {
            $query->where('id', (int) $specific);
        }

        $query->chunkById(100, function ($users) use ($evaluator, $residencyEvaluator, $generator, &$usersProcessed, &$tasksEvaluated): void {
            foreach ($users as $user) {
                $profile = $generator->ensure($user);
                $usersProcessed++;

                // Good-news pass: surface permanent-residency eligibility once
                // the permit clears the track threshold (announce-once inside).
                if (! $this->option('dry-run')) {
                    $residencyEvaluator->evaluate($user, $profile);
                }

                $userTasks = $user->userTasks()->with('task')->open()->get();
                foreach ($userTasks as $userTask) {
                    // Tasks that stopped applying (path switch, attribute
                    // change) must never push reminders.
                    if ($userTask->task === null
                        || $generator->applicability($userTask->task, $profile) !== Applicability::Yes) {
                        continue;
                    }

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
}
