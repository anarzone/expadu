<?php

namespace App\Models;

use App\Enums\DeadlineType;
use App\Enums\Urgency;
use App\Profile\ProfileEngine;
use Carbon\Carbon;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'type', 'title', 'description', 'situation', 'eu_filter', 'applies_if', 'decision_options', 'phase', 'depends_on', 'deadline_type', 'deadline_days', 'urgency', 'links', 'documents_required', 'recurrence_months', 'how_to_steps', 'booking_service_key', 'verified_at', 'outdated_reports', 'is_published'])]
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
            'applies_if' => 'array',
            'decision_options' => 'array',
            'depends_on' => 'array',
            'links' => 'array',
            'documents_required' => 'array',
            'how_to_steps' => 'array',
            'deadline_type' => DeadlineType::class,
            'urgency' => Urgency::class,
            'verified_at' => 'datetime',
            'is_published' => 'boolean',
        ];
    }

    /**
     * Whether this task applies to a user's EU/non-EU status.
     */
    public function matchesEuStatus(bool $isEu): bool
    {
        return match ($this->eu_filter) {
            'eu_only' => $isEu,
            'non_eu_only' => ! $isEu,
            default => true,
        };
    }

    public function isRecurring(): bool
    {
        return $this->recurrence_months !== null;
    }

    /**
     * Info cards are good-to-know content: no checkbox, no progress weight.
     */
    public function isInfo(): bool
    {
        return $this->type === 'info';
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
     * Compute the absolute deadline for a given user. Returns null when no
     * date is computable — which the UI may render as "paused" (move-in
     * pending) or "before your visa expires" (D-visa permit window)
     * depending on the user's attributes.
     *
     * @param  array<string, mixed>|null  $attributes  Profile attribute bag; derived from the user when omitted.
     */
    public function computeDeadlineFor(User $user, ?array $attributes = null): ?Carbon
    {
        if (! $this->deadline_days || $this->deadline_type === DeadlineType::None) {
            return null;
        }

        $attributes ??= app(ProfileEngine::class)->build($user)->attributes;

        return match ($this->deadline_type) {
            DeadlineType::DaysSinceArrival => $user->arrival_date
                ? Carbon::parse($user->arrival_date)->addDays($this->deadline_days)
                : null,
            // §17 BMG: the clock starts at move-in. No move-in date yet
            // (temporary housing) → null → the UI shows "paused".
            DeadlineType::DaysSinceMoveIn => ($attributes['moved_in_at'] ?? null)
                ? Carbon::parse($attributes['moved_in_at'])->addDays($this->deadline_days)
                : null,
            // Visa-free entrants get the 90-day clock; D-visa holders' real
            // deadline is their visa expiry — no computable date here.
            DeadlineType::PermitWindow => ($attributes['entry_mode'] ?? 'visa_free') === 'visa_free' && $user->arrival_date
                ? Carbon::parse($user->arrival_date)->addDays($this->deadline_days)
                : null,
            default => null,
        };
    }
}
