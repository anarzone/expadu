<?php

namespace App\Http\Controllers;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use App\Profile\Profile;
use App\Profile\ProfileEngine;
use App\Services\BuergeramtService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BureaucracyController extends Controller
{
    /**
     * Framing-B bureaucracy page payload. Buckets every situation-relevant
     * task into one of four lanes (active / upcoming / completed / not_applicable)
     * so the React side can render the Do-next / Coming-up / Completed sections
     * with no further bucketing logic.
     */
    public function index(Request $request, BuergeramtService $buergeramtService, ProfileEngine $profileEngine): Response
    {
        $user = $request->user();
        $profile = $profileEngine->build($user);

        // Materialise pivots for any branch-relevant Task missing one. The
        // bureaucracy:remind cron does this nightly, but a fresh-onboarded user
        // would otherwise see an empty page until tomorrow.
        $this->ensureUserTasks($user, $profile);

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

        $cards = $userTasks
            ->map(fn (UserTask $ut) => $this->formatCard($ut, $user, $doneKeys, $titlesByKey))
            ->values();

        $buckets = [
            'active' => $cards->filter(fn ($c) => $c['bucket'] === 'active')->values(),
            'upcoming' => $cards->filter(fn ($c) => $c['bucket'] === 'upcoming')->values(),
            'completed' => $cards->filter(fn ($c) => $c['bucket'] === 'completed')->values(),
            'not_applicable' => $cards->filter(fn ($c) => $c['bucket'] === 'not_applicable')->values(),
        ];

        $totalActionable = $buckets['active']->count() + $buckets['upcoming']->count() + $buckets['completed']->count();
        $doneCount = $buckets['completed']->count();

        $slots = $buergeramtService->checkSlots();
        $monitors = $user->slotMonitors()->where('is_active', true)->pluck('office_id')->all();

        return Inertia::render('bureaucracy', [
            'situation' => $user->situation?->value,
            'tasks' => $buckets,
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
     * Ensure a UserTask row exists for every Task matching the user's situation.
     * Idempotent — mirrors the cron's ensureUserTasks() but bounded to page load.
     */
    private function ensureUserTasks(User $user, Profile $profile): void
    {
        $existing = $user->userTasks()->pluck('task_id')->all();

        Task::query()
            ->whereJsonContains('situation', $profile->bureaucracyBranch)
            ->where('is_published', true)
            ->whereNotIn('id', $existing)
            ->get()
            ->filter(fn (Task $task) => $task->matchesEuStatus($profile->isEu))
            ->each(fn (Task $task) => $user->userTasks()->create(['task_id' => $task->id]));
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, int>  $doneKeys
     * @param  array<string, string>  $titlesByKey
     */
    private function formatCard(UserTask $userTask, User $user, array $doneKeys, array $titlesByKey): array
    {
        $task = $userTask->task;
        $deadline = $task->computeDeadlineFor($user);
        $daysRemaining = $deadline
            ? (int) now()->startOfDay()->diffInDays($deadline->startOfDay(), false)
            : null;

        $status = $userTask->status ?? TaskStatus::NotStarted;
        $deadlineTier = $this->deadlineTier($daysRemaining, $status);
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
            'documents_required' => $task->documents_required ?? [],
            'how_to_steps' => $task->how_to_steps ?? [],
            'links' => $task->links ?? [],
            'booking_service_key' => $task->booking_service_key,
            'booking_url' => $bookingUrl,
            'is_applicable' => $userTask->is_applicable,
            'is_recurring' => $task->isRecurring(),
            'blocked' => $blockedBy !== [] && $status !== TaskStatus::Done,
            'blocked_by' => $blockedBy,
            'verified_at' => $task->verified_at?->toDateString(),
            'next_due_at' => $userTask->next_due_at?->toIso8601String(),
            'completed_at' => $userTask->completed_at?->toIso8601String(),
            'bucket' => $bucket,
        ];
    }

    /**
     * Time-to-deadline tier. Done/not-applicable tasks render as 'none'.
     */
    private function deadlineTier(?int $daysRemaining, TaskStatus $status): string
    {
        if ($status === TaskStatus::Done) {
            return 'none';
        }
        if ($daysRemaining === null) {
            return 'no_deadline';
        }
        if ($daysRemaining < 0) {
            return 'overdue';
        }
        if ($daysRemaining <= 3) {
            return 'critical';
        }
        if ($daysRemaining <= 7) {
            return 'urgent';
        }
        if ($daysRemaining <= 14) {
            return 'approaching';
        }

        return 'on_track';
    }

    /**
     * Sort each UserTask into one of four UI lanes.
     */
    private function bucket(UserTask $userTask, string $tier): string
    {
        if (! $userTask->is_applicable) {
            return 'not_applicable';
        }
        if (($userTask->status ?? TaskStatus::NotStarted) === TaskStatus::Done) {
            // A recurring done task with next_due_at in the future still hides
            // until it re-enters the active window.
            if ($userTask->next_due_at && $userTask->next_due_at->isFuture()) {
                return 'completed';
            }

            return 'completed';
        }

        return in_array($tier, ['overdue', 'critical', 'urgent', 'approaching'], true)
            ? 'active'
            : 'upcoming';
    }
}
