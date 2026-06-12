<?php

namespace App\Http\Controllers;

use App\Bureaucracy\PathGenerator;
use App\Enums\DeadlineType;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use App\Profile\Applicability;
use App\Profile\Profile;
use App\Profile\ProfileEngine;
use App\Services\BuergeramtService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BureaucracyController extends Controller
{
    /**
     * The bureaucracy page payload. The path is recomputed from the profile
     * attribute bag on every load (idempotent); cards land in lanes the
     * React side renders without further logic: active / upcoming /
     * completed / not_applicable / info / no_longer_relevant + teasers.
     */
    public function index(Request $request, BuergeramtService $buergeramtService, ProfileEngine $profileEngine, PathGenerator $generator): Response
    {
        $user = $request->user();
        $profile = $generator->ensure($user);

        $userTasks = $user->userTasks()
            ->with('task')
            ->get()
            ->filter(fn (UserTask $ut) => $ut->task !== null && $ut->task->is_published);

        // Done task keys unlock dependants: a card is blocked while any of
        // its depends_on keys is not completed.
        $doneKeys = $userTasks
            ->filter(fn (UserTask $ut) => ($ut->status ?? TaskStatus::NotStarted) === TaskStatus::Done)
            ->map(fn (UserTask $ut) => $ut->task->key)
            ->filter()
            ->flip()
            ->all();
        $titlesByKey = $userTasks
            ->mapWithKeys(fn (UserTask $ut) => [$ut->task->key => $ut->task->title])
            ->all();

        $cards = collect();
        foreach ($userTasks as $userTask) {
            $applicability = $generator->applicability($userTask->task, $profile);

            if ($applicability !== Applicability::Yes) {
                // Progress is never deleted — touched tasks from a previous
                // path show under "no longer relevant"; untouched rows stay
                // in the DB but off the page.
                if ($this->hasProgress($userTask)) {
                    $cards->push([
                        ...$this->formatCard($userTask, $user, $profile, $doneKeys, $titlesByKey),
                        'bucket' => 'no_longer_relevant',
                    ]);
                }

                continue;
            }

            $cards->push($this->formatCard($userTask, $user, $profile, $doneKeys, $titlesByKey));
        }
        $cards = $cards->values();

        $buckets = [
            'active' => $cards->filter(fn ($c) => $c['bucket'] === 'active')->values(),
            'upcoming' => $cards->filter(fn ($c) => $c['bucket'] === 'upcoming')->values(),
            'completed' => $cards->filter(fn ($c) => $c['bucket'] === 'completed')->values(),
            'not_applicable' => $cards->filter(fn ($c) => $c['bucket'] === 'not_applicable')->values(),
            'info' => $cards->filter(fn ($c) => $c['bucket'] === 'info')->values(),
            'no_longer_relevant' => $cards->filter(fn ($c) => $c['bucket'] === 'no_longer_relevant')->values(),
        ];

        // Info cards and ghosts live outside the progress ring.
        $totalActionable = $buckets['active']->count() + $buckets['upcoming']->count() + $buckets['completed']->count();
        $doneCount = $buckets['completed']->count();

        $pathOptions = $profileEngine->pathOptionsFor($user);

        $slots = $buergeramtService->checkSlots();
        $monitors = $user->slotMonitors()->where('is_active', true)->pluck('office_id')->all();

        return Inertia::render('bureaucracy', [
            'situation' => $user->situation?->value,
            'path' => [
                'current' => $user->bureaucracy_path,
                'branch' => $profile->bureaucracyBranch,
                'options' => collect($pathOptions)
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values(),
            ],
            'tasks' => $buckets,
            'teasers' => $generator->teasers($profile),
            'phases' => $this->phases($profile, $buckets['active']->count()),
            'progress' => [
                'done' => $doneCount,
                'total' => $totalActionable,
                'percent' => $totalActionable > 0 ? (int) round(($doneCount / $totalActionable) * 100) : 0,
            ],
            'slots' => $slots,
            'monitors' => $monitors,
            'bookingServices' => collect(BuergeramtService::SERVICES)->map(fn ($s, $key) => [
                'key' => $key,
                'name' => $s['name'],
                'name_en' => $s['name_en'],
                'emoji' => $s['emoji'],
                'duration' => $s['duration'],
                'url' => BuergeramtService::BOOKING_URLS[$s['category']].'&service='.$s['uid'],
            ])->values(),
        ]);
    }

    /**
     * One-time path refinement. User-task rows are kept — the recompute
     * shows the new path's tasks and moves touched old-path tasks to the
     * "no longer relevant" lane. Nothing the user did vanishes.
     */
    public function setPath(Request $request, ProfileEngine $profileEngine): RedirectResponse
    {
        $user = $request->user();
        $options = $profileEngine->pathOptionsFor($user);
        abort_if($options === [], 404);

        $data = $request->validate([
            'path' => ['required', 'string', Rule::in(array_keys($options))],
        ]);

        $user->update(['bureaucracy_path' => $data['path']]);

        return back();
    }

    /**
     * The presentational roadmap: phases are computed from dates, the
     * engine itself doesn't know them.
     *
     * @return array{current: string, blurb: string, steps: list<array{key: string, label: string, state: string}>}
     */
    private function phases(Profile $profile, int $activeCount): array
    {
        $days = $profile->daysSinceArrival();

        $current = match (true) {
            $days === null => 'before',
            $days <= 14 => 'first_14',
            $days <= 90 => 'first_90',
            default => 'settled',
        };

        $matterNow = $activeCount === 1 ? '1 thing matters now' : "{$activeCount} things matter now";
        $blurb = match ($current) {
            'before' => 'Set your arrival date and your plan starts ticking.',
            'first_14' => $activeCount > 0
                ? "The Anmeldung sprint — {$matterNow}."
                : 'The Anmeldung sprint — you\'re ahead of it.',
            'first_90' => $activeCount > 0
                ? "You're in your first 90 days — {$matterNow}."
                : "You're in your first 90 days — nothing is on fire.",
            default => $activeCount > 0
                ? "Settled — {$activeCount} loose end".($activeCount === 1 ? '' : 's').' to tie up.'
                : 'Settled. Renewals and the long game are tracked for you.',
        };

        $order = ['before', 'first_14', 'first_90', 'settled', 'permanent'];
        $labels = ['before' => 'Before you fly', 'first_14' => 'First 14 days', 'first_90' => 'First 90 days', 'settled' => 'Settled', 'permanent' => 'Permanent'];
        $currentIdx = array_search($current, $order, true);

        $steps = [];
        foreach ($order as $i => $key) {
            $steps[] = [
                'key' => $key,
                'label' => $labels[$key],
                'state' => $i < $currentIdx ? 'done' : ($i === $currentIdx ? 'now' : 'ahead'),
            ];
        }

        return ['current' => $current, 'blurb' => $blurb, 'steps' => $steps];
    }

    private function hasProgress(UserTask $userTask): bool
    {
        return ($userTask->status ?? TaskStatus::NotStarted) !== TaskStatus::NotStarted
            || $userTask->completed_at !== null
            || ($userTask->documents_checked ?? []) !== []
            || ! $userTask->is_applicable;
    }

    /**
     * @param  array<string, int>  $doneKeys
     * @param  array<string, string>  $titlesByKey
     * @return array<string, mixed>
     */
    private function formatCard(UserTask $userTask, User $user, Profile $profile, array $doneKeys, array $titlesByKey): array
    {
        $task = $userTask->task;
        $deadline = $task->computeDeadlineFor($user, $profile->attributes);
        $daysRemaining = $deadline
            ? (int) now()->startOfDay()->diffInDays($deadline->startOfDay(), false)
            : null;

        $status = $userTask->status ?? TaskStatus::NotStarted;
        [$deadlineTier, $deadlineNote] = $this->deadlineState($task, $profile, $daysRemaining, $status);
        $bucket = $this->bucket($userTask, $deadlineTier);

        $blockedBy = collect($task->depends_on ?? [])
            ->reject(fn (string $key) => isset($doneKeys[$key]))
            ->map(fn (string $key) => $titlesByKey[$key] ?? $key)
            ->values()
            ->all();

        $bookingUrl = null;
        if ($task->booking_service_key && isset(BuergeramtService::SERVICES[$task->booking_service_key])) {
            $svc = BuergeramtService::SERVICES[$task->booking_service_key];
            $bookingUrl = (BuergeramtService::BOOKING_URLS[$svc['category']] ?? '').'&service='.$svc['uid'];
        }

        return [
            'id' => $userTask->id,
            'task_id' => $task->id,
            'key' => $task->key,
            'type' => $task->type,
            'title' => $task->title,
            'description' => $task->description,
            'phase' => $task->phase,
            'urgency' => $task->urgency->value,
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_tone' => $status->tone(),
            'deadline' => $deadline?->toDateString(),
            'days_remaining' => $daysRemaining,
            'deadline_tier' => $deadlineTier,
            'deadline_note' => $deadlineNote,
            'documents_required' => $task->documents_required ?? [],
            'documents_checked' => $userTask->documents_checked ?? [],
            'decision_options' => $task->decision_options ?? [],
            'how_to_steps' => $task->how_to_steps ?? [],
            'links' => $task->links ?? [],
            'booking_service_key' => $task->booking_service_key,
            'booking_url' => $bookingUrl,
            'is_applicable' => $userTask->is_applicable,
            'is_recurring' => $task->isRecurring(),
            'blocked' => $blockedBy !== [] && $status !== TaskStatus::Done,
            'blocked_by' => $blockedBy,
            'verified_at' => $task->verified_at?->toDateString(),
            'why' => $this->whyLine($task, $profile),
            'next_due_at' => $userTask->next_due_at?->toIso8601String(),
            'completed_at' => $userTask->completed_at?->toIso8601String(),
            'bucket' => $bucket,
        ];
    }

    /**
     * Deadline tier + the human note for the two date-less special states:
     * paused (move-in pending) and the D-visa permit window.
     *
     * @return array{0: string, 1: string|null}
     */
    private function deadlineState(Task $task, Profile $profile, ?int $daysRemaining, TaskStatus $status): array
    {
        if ($status === TaskStatus::Done) {
            return ['none', null];
        }

        if ($daysRemaining === null) {
            if ($task->deadline_type === DeadlineType::DaysSinceMoveIn
                && ($profile->attributes['housing_status'] ?? null) === 'temporary') {
                return ['paused', 'Deadline paused — the clock starts when you move into a long-term address.'];
            }

            if ($task->deadline_type === DeadlineType::PermitWindow
                && ($profile->attributes['entry_mode'] ?? null) === 'd_visa') {
                return ['urgent', 'Due before your visa expires — apply well ahead of that date.'];
            }

            return ['no_deadline', null];
        }

        return [match (true) {
            $daysRemaining < 0 => 'overdue',
            $daysRemaining <= 3 => 'critical',
            $daysRemaining <= 7 => 'urgent',
            $daysRemaining <= 14 => 'approaching',
            default => 'on_track',
        }, null];
    }

    /**
     * "Why am I seeing this" — generated from the task's matched applies_if
     * conditions, in human words. Trust feature: every card can explain itself.
     */
    private function whyLine(Task $task, Profile $profile): ?string
    {
        $group = collect($task->applies_if ?? [])
            ->first(fn (array $group) => Applicability::evaluate([$group], $profile->attributes) === Applicability::Yes);

        if ($group === null) {
            return null;
        }

        $words = [];
        foreach ($group as $attribute => $value) {
            $actual = $profile->attributes[$attribute] ?? null;
            $words[] = match ($attribute) {
                'citizenship_group' => $actual === 'eu' ? 'EU citizen' : 'non-EU citizen',
                'purpose' => match ($actual) {
                    'employment' => 'employee',
                    'study' => 'student',
                    'freelance' => 'self-employed',
                    'family' => 'joining family',
                    default => 'new in Cologne',
                },
                'permit_track' => match ($actual) {
                    'blue_card' => 'Blue Card path',
                    'chancenkarte' => 'Chancenkarte path',
                    default => null,
                },
                'business_type' => $actual === 'gewerbe' ? 'Gewerbe' : null,
                'sponsor' => match ($actual) {
                    'german' => 'joining a German citizen',
                    'eu_citizen' => 'joining an EU citizen',
                    default => null,
                },
                'license_country' => 'foreign driving licence',
                default => null,
            };
        }

        $words = array_values(array_unique(array_filter($words)));

        return $words === [] ? null : 'Why you see this: '.implode(' · ', $words);
    }

    /**
     * Sort each UserTask into a UI lane.
     */
    private function bucket(UserTask $userTask, string $tier): string
    {
        // Info cards are reference content — no lifecycle, own lane.
        if ($userTask->task->isInfo()) {
            return 'info';
        }
        if (! $userTask->is_applicable) {
            return 'not_applicable';
        }
        if (($userTask->status ?? TaskStatus::NotStarted) === TaskStatus::Done) {
            return 'completed';
        }

        // Paused (move-in pending) stays in the attention lane — it's the
        // user's primary next thing even without a ticking clock.
        return in_array($tier, ['overdue', 'critical', 'urgent', 'approaching', 'paused'], true)
            ? 'active'
            : 'upcoming';
    }
}
