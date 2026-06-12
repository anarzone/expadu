<?php

namespace App\Bureaucracy;

use App\Models\Task;
use App\Models\User;
use App\Profile\Applicability;
use App\Profile\Profile;
use App\Profile\ProfileEngine;
use Illuminate\Support\Collection;

/**
 * The path is computed, not enumerated: applicable = published tasks whose
 * applies_if matches the profile's attribute bag. Idempotent and
 * side-effect-free except user_task materialisation — user progress is
 * NEVER deleted on recompute; tasks that stop applying move to a
 * "no longer relevant" presentation, they don't vanish.
 */
class PathGenerator
{
    public function __construct(private ProfileEngine $engine) {}

    /**
     * Materialise user_task rows for every applicable task and return the
     * profile for reuse. Runs on page load and in the nightly cron.
     */
    public function ensure(User $user): Profile
    {
        $profile = $this->engine->build($user);

        $existing = $user->userTasks()->pluck('task_id')->all();

        $this->publishedTasks()
            ->reject(fn (Task $task) => in_array($task->id, $existing, true))
            ->filter(fn (Task $task) => $this->applicability($task, $profile) === Applicability::Yes)
            ->each(fn (Task $task) => $user->userTasks()->create(['task_id' => $task->id]));

        return $profile;
    }

    /**
     * Tri-state applicability. Life-event tasks stay dormant (No, never a
     * teaser) until the event is recorded. Tasks without compiled applies_if
     * (ad-hoc Filament rows) fall back to legacy branch + EU matching.
     */
    public function applicability(Task $task, Profile $profile): Applicability
    {
        if ($task->trigger_event !== null
            && ($profile->attributes["{$task->trigger_event}_at"] ?? null) === null) {
            return Applicability::No;
        }

        if ($task->applies_if !== null && $task->applies_if !== []) {
            return Applicability::evaluate($task->applies_if, $profile->attributes);
        }

        $matches = in_array($profile->bureaucracyBranch, (array) $task->situation, true)
            && $task->matchesEuStatus($profile->isEu);

        return $matches ? Applicability::Yes : Applicability::No;
    }

    /**
     * Teaser cards: published tasks whose verdict hinges on an unanswered
     * attribute that has a defined just-in-time question.
     *
     * @return list<array<string, mixed>>
     */
    public function teasers(Profile $profile): array
    {
        $teasers = [];

        foreach ($this->publishedTasks() as $task) {
            if ($this->applicability($task, $profile) !== Applicability::Unknown) {
                continue;
            }

            $unknown = Applicability::unknownAttributes($task->applies_if, $profile->attributes);
            foreach ($unknown as $attribute) {
                $question = ProfileEngine::TEASER_QUESTIONS[$attribute] ?? null;
                if ($question === null) {
                    continue;
                }

                $teasers[] = [
                    'task_id' => $task->id,
                    'title' => $task->title,
                    'attribute' => $attribute,
                    'question' => $question['question'],
                    'hint' => $question['hint'],
                    'options' => $question['options'],
                ];

                break; // one question per teaser card
            }
        }

        return $teasers;
    }

    /**
     * @return Collection<int, Task>
     */
    private function publishedTasks(): Collection
    {
        return Task::query()->where('is_published', true)->get();
    }
}
