<?php

namespace App\Models;

use App\Enums\DeadlineType;
use App\Enums\Urgency;
use Carbon\Carbon;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'description', 'situation', 'phase', 'deadline_type', 'deadline_days', 'urgency', 'links', 'documents_required', 'recurrence_months', 'how_to_steps', 'booking_service_key'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'situation' => 'array',
            'links' => 'array',
            'documents_required' => 'array',
            'how_to_steps' => 'array',
            'deadline_type' => DeadlineType::class,
            'urgency' => Urgency::class,
        ];
    }

    public function isRecurring(): bool
    {
        return $this->recurrence_months !== null;
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_tasks')
            ->withPivot('completed_at', 'snoozed_until', 'notes')
            ->withTimestamps();
    }

    /** @return HasMany<UserTask, $this> */
    public function userTasks(): HasMany
    {
        return $this->hasMany(UserTask::class);
    }

    /**
     * Compute the absolute deadline for a given user.
     */
    public function computeDeadlineFor(User $user): ?Carbon
    {
        if (! $this->deadline_days || $this->deadline_type === DeadlineType::None) {
            return null;
        }

        if ($this->deadline_type === DeadlineType::DaysSinceArrival && $user->arrival_date) {
            return Carbon::parse($user->arrival_date)->addDays($this->deadline_days);
        }

        return null;
    }
}
