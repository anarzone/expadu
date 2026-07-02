<?php

namespace App\Http\Controllers;

use App\Bureaucracy\PathGenerator;
use App\Bureaucracy\PermanentResidencyEligibility;
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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BureaucracyController extends Controller
{
    /**
     * The "I'm settled" bulk action. Arrival basics — matched by key SUFFIX so
     * every branch variant (nee.anmeldung, core.anmeldung, stu.anmeldung …) is
     * covered — are marked Done; the journey-to-permanent-residency content
     * that's wrong once you hold PR is marked not-applicable. Everything else
     * (Schufa, church tax, Haftpflicht, the annual tax return) is left as
     * evergreen reference.
     *
     * @var list<string>
     */
    private const ARRIVAL_BASICS = ['anmeldung', 'steuer_id', 'health_insurance', 'bank_account', 'rundfunkbeitrag'];

    /** @var list<string> Journey-to-PR content retired once the user holds permanent residency. */
    private const PR_JOURNEY_KEYS = ['shared.long_game', 'bc.ne_fast_track', 'shared.fiktionsbescheinigung'];

    /**
     * The bureaucracy page payload. The path is recomputed from the profile
     * attribute bag on every load (idempotent); cards land in lanes the
     * React side renders without further logic: active / upcoming /
     * completed / not_applicable / info / no_longer_relevant + teasers.
     */
    public function index(Request $request, BuergeramtService $buergeramtService, ProfileEngine $profileEngine, PathGenerator $generator, PermanentResidencyEligibility $eligibility): Response
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
                        ...$this->formatCard($userTask, $user, $profile, $doneKeys, $titlesByKey, $buergeramtService),
                        'bucket' => 'no_longer_relevant',
                    ]);
                }

                continue;
            }

            $cards->push($this->formatCard($userTask, $user, $profile, $doneKeys, $titlesByKey, $buergeramtService));
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

        $settled = ($profile->attributes['settled_at'] ?? null) !== null;
        // Detect-and-suggest: offer the one-tap "I'm settled" to long-term
        // residents who still carry open arrival basics. Never auto-applied.
        $settledSuggestion = ! $settled
            && ($profile->daysSinceArrival() ?? 0) > 365
            && $userTasks->contains(fn (UserTask $ut) => ($ut->status ?? TaskStatus::NotStarted) !== TaskStatus::Done
                && $ut->is_applicable
                && in_array(Str::afterLast($ut->task->key ?? '', '.'), self::ARRIVAL_BASICS, true));

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
            'eligibility' => $eligibility->for($profile),
            'settled' => $settled,
            'settledSuggestion' => $settledSuggestion,
            // Which life events the user has recorded — drives the info-card
            // buttons ("We just had a baby" hides once recorded).
            'lifeEvents' => collect(ProfileEngine::DATE_ATTRIBUTES)
                ->mapWithKeys(fn (string $attr) => [$attr => ($profile->attributes[$attr] ?? null) !== null])
                ->all(),
            'progress' => [
                'done' => $doneCount,
                'total' => $totalActionable,
                'percent' => $totalActionable > 0 ? (int) round(($doneCount / $totalActionable) * 100) : 0,
            ],
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
     * One-tap "I'm already settled here". Marks the arrival basics Done and
     * retires the journey-to-permanent-residency content for residents who
     * handled all this years ago — a fast, reversible bulk skip. Every task
     * stays re-openable from its card; nothing is hidden silently.
     */
    public function settle(Request $request): RedirectResponse
    {
        $user = $request->user();

        $user->userTasks()->with('task')->get()->each(function (UserTask $userTask) {
            $key = $userTask->task?->key;
            if ($key === null) {
                return;
            }

            if (in_array(Str::afterLast($key, '.'), self::ARRIVAL_BASICS, true)) {
                if (($userTask->status ?? TaskStatus::NotStarted) !== TaskStatus::Done) {
                    $userTask->markDone();
                }
            } elseif (in_array($key, self::PR_JOURNEY_KEYS, true)) {
                $userTask->update(['is_applicable' => false]);
            }
        });

        // Record the declaration: the suggestion banner won't return and the
        // "you may qualify for permanent residency" hint stays suppressed.
        $user->update([
            'profile_attributes' => [
                ...(array) ($user->profile_attributes ?? []),
                'settled_at' => now()->toDateString(),
            ],
        ]);

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
            // Declaring "I'm settled" (holding permanent residency) reaches the
            // final milestone — otherwise 'permanent' is never the current phase
            // and dangles ahead forever, even once you already hold it.
            ($profile->attributes['settled_at'] ?? null) !== null => 'permanent',
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
            'permanent' => 'You hold permanent residency — the renewal treadmill is over. Citizenship is the optional next chapter.',
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
    private function formatCard(UserTask $userTask, User $user, Profile $profile, array $doneKeys, array $titlesByKey, BuergeramtService $buergeramtService): array
    {
        $task = $userTask->task;
        // A booked appointment IS the deadline.
        $deadline = $userTask->appointment_at
            ? $userTask->appointment_at->copy()
            : $task->computeDeadlineFor($user, $profile->attributes);

        // Documents may cross-link the task that produces them
        // ("Meldebescheinigung ← from Anmeldung").
        $documents = collect($task->documents_required ?? [])
            ->map(function ($doc) use ($titlesByKey) {
                if (is_array($doc) && isset($doc['from'])) {
                    $doc['from_title'] = $titlesByKey[$doc['from']] ?? null;
                }

                return $doc;
            })
            ->all();
        $daysRemaining = $deadline
            ? (int) now()->startOfDay()->diffInDays($deadline->startOfDay(), false)
            : null;

        $status = $userTask->status ?? TaskStatus::NotStarted;
        [$deadlineTier, $deadlineNote] = $this->deadlineState($task, $profile, $daysRemaining, $status);
        if ($userTask->appointment_at && $status !== TaskStatus::Done) {
            $deadlineNote = 'Your appointment: '.$userTask->appointment_at->format('D j M, H:i');
        }
        // What the note strip can DO about a missing date.
        $deadlineAction = match (true) {
            $deadlineTier === 'paused' => 'moved_in',
            $deadline === null
                && $task->deadline_type === DeadlineType::PermitWindow
                && ($profile->attributes['entry_mode'] ?? null) === 'd_visa'
                && $status !== TaskStatus::Done => 'visa_expiry',
            default => null,
        };
        $bucket = $this->bucket($userTask, $deadlineTier);

        $blockedBy = collect($task->depends_on ?? [])
            ->reject(fn (string $key) => isset($doneKeys[$key]))
            ->map(fn (string $key) => $titlesByKey[$key] ?? $key)
            ->values()
            ->all();

        $bookingKey = $task->booking_service_key;
        $bookingUrl = null;
        $category = null;
        if ($bookingKey && isset(BuergeramtService::SERVICES[$bookingKey])) {
            $svc = BuergeramtService::SERVICES[$bookingKey];
            $category = $svc['category'];
            $bookingUrl = (BuergeramtService::BOOKING_URLS[$category] ?? '').'&service='.$svc['uid'];
        } elseif ($bookingKey && isset(BuergeramtService::BOOKING_URLS[$bookingKey])) {
            // Category-level keys (auslaenderbehoerde, finanzamt, kfz) link
            // to the category's booking calendar without a service deep-link.
            $category = $bookingKey;
            $bookingUrl = BuergeramtService::BOOKING_URLS[$bookingKey];
        }

        // Only pin an office (address + Take-me-there) for single-site
        // services. Bürgeramt services span ten Kundenzentren and the office
        // is chosen at the end of the city's booking flow, so we don't guess.
        $office = in_array($category, BuergeramtService::SINGLE_SITE_CATEGORIES, true)
            ? $buergeramtService->officeForTask($bookingKey, $user->veedel)
            : null;

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
            'deadline_action' => $deadlineAction,
            'documents_required' => $documents,
            'documents_checked' => $userTask->documents_checked ?? [],
            'decision_options' => $task->decision_options ?? [],
            'how_to_steps' => $task->how_to_steps ?? [],
            'links' => $task->links ?? [],
            'booking_service_key' => $task->booking_service_key,
            'booking_url' => $bookingUrl,
            'office' => $office,
            'appointment_at' => $userTask->appointment_at?->toIso8601String(),
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
                return ['urgent', 'Due before your visa expires — tell us the expiry date and this becomes a real countdown.'];
            }

            return ['no_deadline', null];
        }

        if ($task->deadline_type === DeadlineType::PermitWindow
            && ($profile->attributes['entry_mode'] ?? null) === 'd_visa') {
            return [match (true) {
                $daysRemaining < 0 => 'overdue',
                $daysRemaining <= 7 => 'critical',
                $daysRemaining <= 21 => 'urgent',
                $daysRemaining <= 45 => 'approaching',
                default => 'on_track',
            }, 'Anchored to your visa expiry — the application must be in before then.'];
        }

        return [match (true) {
            // Months past the deadline: the window has closed — soften from a
            // precise "overdue by N days" countdown to a lapsed loose end.
            $daysRemaining < -UserTask::STALE_OVERDUE_DAYS => 'lapsed',
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
        // An explicit opt-out wins over everything — a "doesn't apply to me"
        // card belongs in Not applicable, even when it's an info card (e.g. the
        // PR-journey notes a settled resident retires via "I'm settled").
        if (! $userTask->is_applicable) {
            return 'not_applicable';
        }
        // Info cards are reference content — no lifecycle, own lane.
        if ($userTask->task->isInfo()) {
            return 'info';
        }
        if (($userTask->status ?? TaskStatus::NotStarted) === TaskStatus::Done) {
            return 'completed';
        }

        // Paused (move-in pending) stays in the attention lane — it's the
        // user's primary next thing even without a ticking clock.
        return in_array($tier, ['overdue', 'critical', 'urgent', 'approaching', 'paused', 'lapsed'], true)
            ? 'active'
            : 'upcoming';
    }
}
