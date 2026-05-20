<?php

namespace App\Models;

use App\Enums\DeadlineType;
use App\Enums\TaskStatus;
use Carbon\Carbon;
use Database\Factories\UserTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'task_id', 'status', 'completed_at', 'next_due_at', 'is_applicable', 'snoozed_until', 'notes'])]
class UserTask extends Model
{
    /** @use HasFactory<UserTaskFactory> */
    use HasFactory;

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
     * Compute the absolute deadline date for this user+task pair.
     */
    public function getAbsoluteDeadlineAttribute(): ?Carbon
    {
        $task = $this->task;
        $user = $this->user;

        if (! $task || ! $user || ! $task->deadline_days) {
            return null;
        }

        if ($task->deadline_type === DeadlineType::DaysSinceArrival && $user->arrival_date) {
            return Carbon::parse($user->arrival_date)->addDays($task->deadline_days);
        }

        return null;
    }

    /**
     * Compute deadline status with urgency and priority boost.
     *
     * @return array{days_remaining: int|null, urgency: string, label: string, priority_boost: int}
     */
    public function getDeadlineStatusAttribute(): array
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
            return [
                'days_remaining' => $daysRemaining,
                'urgency' => 'overdue',
                'label' => 'Overdue by '.abs($daysRemaining).' days',
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
