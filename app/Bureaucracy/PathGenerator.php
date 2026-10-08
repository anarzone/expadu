<?php

namespace App\Bureaucracy;

use App\Bureaucracy\Cases\CaseAttributes;
use App\Bureaucracy\Facts\ConfirmedBureaucracyAttributes;
use App\Models\BureaucracyCase;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
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
    public function __construct(private ProfileEngine $engine, private GuidancePublication $publication) {}

    /** Read-only compatibility projection using the same confirmed facts as the case engine. */
    public function profileFor(User $user): Profile
    {
        $base = $this->engine->build($user);
        $case = $user->exists
            ? BureaucracyCase::query()->where('user_id', $user->getKey())->first()
            : ($user->relationLoaded('bureaucracyCase') ? $user->bureaucracyCase : null);
        $attributes = $case instanceof BureaucracyCase
            ? app(CaseAttributes::class)->for($case->setRelation('user', $user))
            : app(ConfirmedBureaucracyAttributes::class)->forUser($user);

        return new Profile(...[...get_object_vars($base), 'attributes' => $attributes]);
    }

    /**
     * Materialise user_task rows for every applicable task and return the
     * profile for reuse. Runs on page load and in the nightly cron.
     */
    public function ensure(User $user): Profile
    {
        $profile = $this->profileFor($user);

        if (BureaucracyCase::query()->where('user_id', $user->id)->where('status', 'erased')->exists()) {
            return $profile;
        }

        $existing = $user->userTasks()->pluck('task_id')->all();

        $missing = $this->publishedTasks()
            ->reject(fn (Task $task) => in_array($task->id, $existing, true))
            ->filter(fn (Task $task) => $this->applicability($task, $profile) === Applicability::Yes);

        // One batched INSERT, not one query per task — a fresh onboarding
        // materialises dozens of rows on its very first page load. Every other
        // user_tasks column carries a DB default or is nullable.
        if ($missing->isNotEmpty()) {
            $now = now();
            UserTask::query()->insert(
                $missing->map(fn (Task $task) => [
                    'user_id' => $user->id,
                    'task_id' => $task->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
            );
        }

        return $profile;
    }

    /**
     * Tri-state applicability. Life-event tasks stay dormant (No, never a
     * teaser) until the event is recorded. Tasks without compiled applies_if
     * (ad-hoc Filament rows) evaluate their branch predicates against the same
     * confirmed answers, never the discovery profile's inferred identity.
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

        $groups = [];
        foreach ((array) $task->situation as $branch) {
            if (! isset(ProfileEngine::BRANCH_PREDICATES[$branch])) {
                return Applicability::Unknown;
            }
            $groups[] = ProfileEngine::BRANCH_PREDICATES[$branch];
        }
        if ($groups === [] && $task->coverage_scope !== 'universal') {
            return Applicability::Unknown;
        }

        $branchVerdict = Applicability::evaluate($groups, $profile->attributes);
        $euVerdict = match ($task->eu_filter) {
            'eu_only' => Applicability::evaluate([['citizenship_group' => 'eu']], $profile->attributes),
            'non_eu_only' => Applicability::evaluate([['citizenship_group' => 'non_eu']], $profile->attributes),
            'all', null => Applicability::Yes,
            default => Applicability::Unknown,
        };

        if ($branchVerdict === Applicability::No || $euVerdict === Applicability::No) {
            return Applicability::No;
        }

        return $branchVerdict === Applicability::Yes && $euVerdict === Applicability::Yes
            ? Applicability::Yes : Applicability::Unknown;
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
        return $this->publication->tasks();
    }
}
