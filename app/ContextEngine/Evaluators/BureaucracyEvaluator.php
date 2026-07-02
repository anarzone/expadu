<?php

namespace App\ContextEngine\Evaluators;

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\ContextEngine\Scorer;
use App\Enums\TaskStatus;
use App\Models\User;
use App\Models\UserTask;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Surfaces bureaucracy tasks that are crossing into urgent / critical / overdue
 * tiers. Driven by the bureaucracy:remind scheduled command — not event-driven —
 * because the trigger is a daily check of deadlines, not an external signal.
 *
 * First-crossing dedup: once a task is announced as "urgent", we don't push
 * again until it enters a new tier. Stored in Redis as
 *   bureaucracy_pushed:{userTaskId}:{tier}    TTL = 30 days
 * so the user isn't spammed every morning about the same overdue task.
 */
class BureaucracyEvaluator
{
    public function __construct(
        private ActionBus $bus,
        private Scorer $scorer,
    ) {}

    /**
     * Evaluate one user_task for the given user. If the deadline tier deserves
     * a ScoredAction we insert it; the push channel is gated by first-crossing
     * dedup so the user only gets a notification once per tier transition.
     */
    public function evaluate(User $user, UserTask $userTask): void
    {
        $task = $userTask->task;
        if (! $task) {
            return;
        }

        if (! $userTask->is_applicable || $userTask->status === TaskStatus::Done) {
            return;
        }

        // A booked appointment IS the deadline (absolute_deadline prefers it):
        // someone who booked for the 28th must not be nagged "overdue!" off the
        // rule-based date, and DOES deserve day-before/day-of reminders.
        $appointment = $userTask->appointment_at;
        $deadline = $userTask->absolute_deadline;
        if (! $deadline) {
            return;
        }

        $daysRemaining = (int) now()->startOfDay()->diffInDays($deadline->startOfDay(), false);
        $tier = $appointment !== null
            ? $this->classifyAppointmentTier($daysRemaining)
            : $this->classifyTier($daysRemaining);
        if ($tier === null) {
            return;
        }

        [$severity, $validUntil] = $this->tierMeta($tier, $deadline);

        $channels = [
            ScoredAction::CHANNEL_DASHBOARD,
            ScoredAction::CHANNEL_ALERT_PAGE,
        ];
        if ($this->shouldPushFirstCrossing($userTask->id, $tier)) {
            $channels[] = ScoredAction::CHANNEL_PUSH;
        }

        $score = $this->scorer->score(
            severity: $severity,
            personalRelevance: Scorer::RELEVANCE_SITUATION_MATCH,
            temporalRelevance: Scorer::TEMPORAL_INSIDE_WINDOW,
        );

        $action = new ScoredAction(
            type: 'bureaucracy_task',
            actionKey: "bureaucracy:{$userTask->id}:user:{$user->id}",
            score: $score,
            severity: $severity,
            validUntil: CarbonImmutable::instance($validUntil),
            deliverChannels: $channels,
            payload: [
                'user_task_id' => $userTask->id,
                'task_id' => $task->id,
                'title' => $task->title,
                'status' => $userTask->status?->value ?? TaskStatus::NotStarted->value,
                'days_remaining' => $daysRemaining,
                'tier' => $tier,
                'deadline' => $deadline->toDateString(),
                'appointment_at' => $appointment?->toIso8601String(),
                'booking_service_key' => $task->booking_service_key,
            ],
            createdAt: CarbonImmutable::now(),
        );

        $this->bus->insert($user, $action);
    }

    /**
     * Returns 'overdue' | 'critical' | 'urgent' | null. Anything beyond 14
     * days remaining doesn't merit a ScoredAction yet.
     */
    private function classifyTier(int $daysRemaining): ?string
    {
        if ($daysRemaining < 0) {
            return 'overdue';
        }
        if ($daysRemaining <= 3) {
            return 'critical';
        }
        if ($daysRemaining <= 7) {
            return 'urgent';
        }

        return null;
    }

    /**
     * A booked appointment gets its own arc — a "coming up" nudge, a
     * day-before reminder, a day-of reminder — and goes SILENT once the slot
     * has passed (nagging "overdue" about an appointment already attended, or
     * missed, helps no one; the checklist still shows the loose end).
     */
    private function classifyAppointmentTier(int $daysRemaining): ?string
    {
        return match (true) {
            $daysRemaining < 0 => null,
            $daysRemaining === 0 => 'appointment_today',
            $daysRemaining === 1 => 'appointment_tomorrow',
            $daysRemaining <= 7 => 'appointment_soon',
            default => null,
        };
    }

    /**
     * @return array{0: string, 1: CarbonInterface}
     */
    private function tierMeta(string $tier, CarbonInterface $deadline): array
    {
        return match ($tier) {
            'overdue' => ['critical', now()->addDays(7)],
            'critical', 'appointment_today' => ['critical', $deadline->copy()->endOfDay()],
            'urgent', 'appointment_tomorrow' => ['major', $deadline],
            default => ['moderate', $deadline],
        };
    }

    /**
     * Atomic first-crossing check. Returns true on the *first* time this
     * (userTask, tier) pair lands in the dedup set, false thereafter for 30d.
     */
    private function shouldPushFirstCrossing(int $userTaskId, string $tier): bool
    {
        $key = "bureaucracy_pushed:{$userTaskId}:{$tier}";

        return Cache::add($key, true, now()->addDays(30));
    }
}
