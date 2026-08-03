<?php

namespace App\Models;

use App\Enums\DeadlineType;
use App\Enums\Urgency;
use App\Profile\ProfileEngine;
use Carbon\Carbon;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'type', 'title', 'description', 'situation', 'eu_filter', 'applies_if', 'decision_options', 'trigger_event', 'phase', 'depends_on', 'deadline_type', 'deadline_days', 'urgency', 'links', 'documents_required', 'recurrence_months', 'how_to_steps', 'booking_service_key', 'verified_at', 'outdated_reports', 'is_published', 'jurisdiction', 'legal_sources', 'review_status', 'source_verification', 'reviewed_by', 'content_version', 'effective_from', 'effective_to', 'review_due_at', 'conflicts_with', 'coverage_scope', 'deadline_fact_key'])]
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
            'legal_sources' => 'array',
            'conflicts_with' => 'array',
            'deadline_type' => DeadlineType::class,
            'urgency' => Urgency::class,
            'verified_at' => 'datetime',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'review_due_at' => 'date',
            'is_published' => 'boolean',
        ];
    }

    /**
     * Restrict deterministic matching to reviewed rules whose approval window
     * is still current. The importer and coverage gate enforce source details.
     */
    public function scopeAuthoritative(Builder $query): Builder
    {
        $today = today()->toDateString();

        return $query
            ->where('is_published', true)
            ->where('review_status', 'approved')
            ->whereNotNull('jurisdiction')
            ->where('jurisdiction', '<>', '')
            ->whereNotNull('content_version')
            ->where('content_version', '<>', '')
            ->whereNotNull('reviewed_by')
            ->where('reviewed_by', '<>', '')
            ->whereNotNull('verified_at')
            ->whereNotNull('legal_sources')
            ->whereJsonLength('legal_sources', '>', 0)
            ->whereIn('source_verification', ['dual_source', 'single_source_approved'])
            ->whereDate('review_due_at', '>=', $today)
            ->where(function (Builder $builder) use ($today): void {
                $builder->whereNull('effective_from')->orWhereDate('effective_from', '<=', $today);
            })
            ->where(function (Builder $builder) use ($today): void {
                $builder->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today);
            });
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
        if ($this->deadline_type === DeadlineType::None) {
            return null;
        }

        $attributes ??= app(ProfileEngine::class)->build($user)->attributes;

        if ($this->deadline_type === DeadlineType::FactDate) {
            $factDate = is_string($this->deadline_fact_key)
                ? ($attributes[$this->deadline_fact_key] ?? null)
                : null;

            if (! is_string($factDate) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $factDate) !== 1) {
                return null;
            }

            $deadline = Carbon::createFromFormat('!Y-m-d', $factDate);

            return $deadline->format('Y-m-d') === $factDate ? $deadline : null;
        }

        if (! $this->deadline_days) {
            return null;
        }

        return match ($this->deadline_type) {
            DeadlineType::DaysSinceArrival => $user->arrival_date
                ? Carbon::parse($user->arrival_date)->addDays($this->deadline_days)
                : null,
            // §17 BMG: the clock starts at move-in. No move-in date yet
            // (temporary housing) → null → the UI shows "paused".
            DeadlineType::DaysSinceMoveIn => ($attributes['moved_in_at'] ?? null)
                ? Carbon::parse($attributes['moved_in_at'])->addDays($this->deadline_days)
                : null,
            // Visa-free entrants get the 90-day clock; D-visa holders'
            // real deadline is their visa expiry (when they've told us).
            DeadlineType::PermitWindow => match ($attributes['entry_mode'] ?? 'visa_free') {
                'visa_free' => $user->arrival_date
                    ? Carbon::parse($user->arrival_date)->addDays($this->deadline_days)
                    : null,
                'd_visa' => ($attributes['visa_expires_at'] ?? null)
                    ? Carbon::parse($attributes['visa_expires_at'])
                    : null,
                default => null,
            },
            // Life-event tasks anchor on the recorded event date.
            DeadlineType::DaysSinceEvent => $this->trigger_event && ($attributes["{$this->trigger_event}_at"] ?? null)
                ? Carbon::parse($attributes["{$this->trigger_event}_at"])->addDays($this->deadline_days)
                : null,
            default => null,
        };
    }
}
