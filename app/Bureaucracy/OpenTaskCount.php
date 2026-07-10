<?php

namespace App\Bureaucracy;

use App\Models\User;
use App\Models\UserTask;
use App\Profile\ProfileEngine;

/**
 * The sidebar's Bureaucracy badge count: open action tasks that need attention
 * now — overdue, or due within the next two weeks. Deliberately NOT "every open
 * task": a fresh full checklist has a dozen-plus open items, and badging that in
 * solid red would read as a backlog on fire. This mirrors the red pill's meaning
 * ("attention, not an action") and the Today screen's "Right now" lane, so the
 * number a user sees on the nav matches what's actually pressing.
 *
 * Cheap by design: ProfileEngine::build reads the already-loaded user (no
 * query), and the shared attribute bag is passed into computeDeadlineFor so the
 * deadline math never rebuilds the profile per task.
 */
class OpenTaskCount
{
    /** A task due within this many days (or already overdue) needs attention. */
    private const ATTENTION_WINDOW_DAYS = 14;

    public function __construct(private ProfileEngine $engine) {}

    public function forUser(User $user): int
    {
        $attributes = $this->engine->build($user)->attributes;

        return $user->userTasks()
            ->open()
            ->whereHas('task', fn ($query) => $query->where('is_published', true)->where('type', 'task'))
            ->with('task')
            ->get()
            ->filter(function (UserTask $userTask) use ($user, $attributes): bool {
                // A booked appointment IS the deadline; otherwise fall back to
                // the task's computed deadline for this profile.
                $deadline = $userTask->appointment_at
                    ?? $userTask->task?->computeDeadlineFor($user, $attributes);

                if ($deadline === null) {
                    return false;
                }

                // Negative = overdue, positive = days ahead (same convention as
                // BureaucracyController's deadline tiers).
                $daysRemaining = (int) now()->startOfDay()->diffInDays($deadline->startOfDay(), false);

                return $daysRemaining <= self::ATTENTION_WINDOW_DAYS;
            })
            ->count();
    }
}
