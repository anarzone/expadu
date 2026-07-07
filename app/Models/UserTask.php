<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Carbon\Carbon;
use Database\Factories\UserTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'task_id', 'status', 'completed_at', 'next_due_at', 'is_applicable', 'snoozed_until', 'notes', 'documents_checked', 'appointment_at'])]
class UserTask extends Model
{
    /** @use HasFactory<UserTaskFactory> */
    use HasFactory;

    /**
     * A deadline only weeks past is a fresh, chase-it miss; once it's months
     * past, the precise "overdue by N days" countdown is noise (and the rule
     * itself has often changed — e.g. a foreign licence past its 6-month
     * conversion window). Beyond this many days overdue a deadline is "lapsed":
     * softened and dropped out of the urgent hero, but still a visible loose end.
     */
    public const STALE_OVERDUE_DAYS = 60;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'completed_at' => 'datetime',
            'next_due_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'is_applicable' => 'boolean',
            'documents_checked' => 'array',
            'appointment_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Compute the absolute deadline date for this user+task pair. A booked
     * appointment IS the deadline — reminders fire off it automatically.
     */
    public function getAbsoluteDeadlineAttribute(): ?Carbon
    {
        if ($this->appointment_at !== null) {
            return Carbon::parse($this->appointment_at);
        }

        $task = $this->task;
        $user = $this->user;

        if (! $task || ! $user) {
            return null;
        }

        return $task->computeDeadlineFor($user);
    }

    /** @var array{days_remaining: int|null, urgency: string, label: string, priority_boost: int}|null */
    private ?array $deadlineStatusMemo = null;

    /**
     * Deadline status with urgency and priority boost. Memoised per instance:
     * the home feed reads it several times per task (tiles + paperwork rail sort
     * + rail card), and each computation rebuilds the profile — once is enough
     * within a request, and the feed never mutates a task after loading it.
     *
     * @return array{days_remaining: int|null, urgency: string, label: string, priority_boost: int}
     */
    public function getDeadlineStatusAttribute(): array
    {
        return $this->deadlineStatusMemo ??= $this->computeDeadlineStatus();
    }

    /**
     * @return array{days_remaining: int|null, urgency: string, label: string, priority_boost: int}
     */
    private function computeDeadlineStatus(): array
    {
        $deadline = $this->absolute_deadline;

        if (! $deadline) {
            return [
                'days_remaining' => null,
                'urgency' => 'none',
                'label' => 'No deadline',
                'priority_boost' => 0,
            ];
        }

        $daysRemaining = (int) now()->startOfDay()->diffInDays($deadline->startOfDay(), false);

        if ($daysRemaining < 0) {
            $daysOverdue = abs($daysRemaining);

            // Lapsed: months past the deadline — the window has clearly closed.
            // Stop the alarming countdown and drop it out of the urgent hero
            // (TileComposer only tiles overdue/critical/urgent); it stays on the
            // checklist as a loose end, just no longer "right now".
            if ($daysOverdue > self::STALE_OVERDUE_DAYS) {
                return [
                    'days_remaining' => $daysRemaining,
                    'urgency' => 'lapsed',
                    'label' => 'Overdue — well past the deadline',
                    'priority_boost' => 5,
                ];
            }

            return [
                'days_remaining' => $daysRemaining,
                'urgency' => 'overdue',
                'label' => 'Overdue by '.$daysOverdue.' days',
                'priority_boost' => 50,
            ];
        }

        if ($daysRemaining <= 3) {
            return [
                'days_remaining' => $daysRemaining,
                'urgency' => 'critical',
                'label' => $daysRemaining === 0 ? 'Due today' : "{$daysRemaining} days left",
                'priority_boost' => 40,
            ];
        }

        if ($daysRemaining <= 7) {
            return [
                'days_remaining' => $daysRemaining,
                'urgency' => 'urgent',
                'label' => "{$daysRemaining} days left",
                'priority_boost' => 25,
            ];
        }

        if ($daysRemaining <= 14) {
            return [
                'days_remaining' => $daysRemaining,
                'urgency' => 'approaching',
                'label' => "{$daysRemaining} days left",
                'priority_boost' => 10,
            ];
        }

        return [
            'days_remaining' => $daysRemaining,
            'urgency' => 'on_track',
            'label' => "{$daysRemaining} days left",
            'priority_boost' => 0,
        ];
    }

    /**
     * Filter out snoozed tasks.
     *
     * @param  Builder<UserTask>  $query
     * @return Builder<UserTask>
     */
    public function scopeNotSnoozed(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('snoozed_until')
                ->orWhere('snoozed_until', '<', now());
        });
    }

    /**
     * Open = anything the user can still act on. Excludes done + not-applicable.
     *
     * @param  Builder<UserTask>  $query
     * @return Builder<UserTask>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('is_applicable', true)
            ->where('status', '!=', TaskStatus::Done->value);
    }

    /**
     * Mark this task done. For recurring tasks, also schedules the next instance.
     */
    public function markDone(): void
    {
        $this->status = TaskStatus::Done;
        $this->completed_at = now();

        $task = $this->task;
        if ($task && $task->isRecurring()) {
            $this->next_due_at = now()->addMonths((int) $task->recurrence_months);
        }

        $this->save();
    }
}
