<?php

namespace App\Models;

use App\Bureaucracy\Facts\CalendarDate;
use App\Bureaucracy\PathGenerator;
use App\Enums\DeadlineType;
use App\Enums\Urgency;
use App\Profile\Applicability;
use Carbon\Carbon;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'type', 'title', 'description', 'description_variants', 'situation', 'eu_filter', 'applies_if', 'decision_options', 'trigger_event', 'phase', 'depends_on', 'deadline_type', 'deadline_days', 'urgency', 'links', 'documents_required', 'recurrence_months', 'how_to_steps', 'booking_service_key', 'verified_at', 'outdated_reports', 'is_published', 'jurisdiction', 'legal_sources', 'review_status', 'source_verification', 'reviewed_by', 'content_version', 'effective_from', 'effective_to', 'review_due_at', 'conflicts_with', 'coverage_scope', 'deadline_fact_key'])]
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
            'description_variants' => 'array',
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
     * The description as this person should read it: the shared paragraph,
     * followed by any variant addressed to them.
     *
     * Variants are additive rather than replacements — the universal statement
     * (§17 BMG, fourteen days) must reach everyone, and a branch paragraph
     * qualifies it rather than standing in for it. More than one can match: a
     * non-EU parent is both.
     *
     * Only a definite No drops a paragraph, matching how documents behave. An
     * unanswered question leaves it in, because the failure we are avoiding is
     * someone never being told about the birth certificates.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function descriptionFor(array $attributes): ?string
    {
        $variants = collect($this->description_variants ?? [])
            ->filter(fn (mixed $variant): bool => is_array($variant) && filled($variant['body'] ?? null))
            ->filter(fn (array $variant): bool => Applicability::evaluate(
                is_array($variant['applies_if'] ?? null) ? $variant['applies_if'] : null,
                $attributes,
            ) !== Applicability::No)
            ->map(fn (array $variant): string => trim((string) $variant['body']));

        if ($variants->isEmpty()) {
            return $this->description;
        }

        return collect([trim((string) $this->description), ...$variants])
            ->filter()
            ->implode("\n\n");
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
     * date is computable. A missing date remains unknown, never proof that an
     * obligation is paused or that a different entry window applies.
     *
     * @param  array<string, mixed>|null  $attributes  Profile attribute bag; derived from the user when omitted.
     */
    public function computeDeadlineFor(User $user, ?array $attributes = null): ?Carbon
    {
        if ($this->deadline_type === DeadlineType::None) {
            return null;
        }

        $attributes ??= app(PathGenerator::class)->profileFor($user)->attributes;
        // Prefer the confirmed arrival answer: a present-but-null key means the
        // answer was retired or is disputed, so the raw profile column it may
        // have outlived must not stand in for it.
        $arrival = CalendarDate::historical(array_key_exists('arrival_date', $attributes)
            ? $attributes['arrival_date']
            : $user->arrival_date?->toDateString());

        if ($this->deadline_type === DeadlineType::FactDate) {
            // A title expiry only means something once we know which title it
            // belongs to: an unknown title may be an unlimited settlement permit.
            $title = $attributes['current_residence_title'] ?? null;
            if ($this->deadline_fact_key === 'residence_title_expires_at'
                && ($title === null || in_array($title, ['settlement_permit_9', 'settlement_permit_18c', 'settlement_permit_unknown'], true))) {
                return null;
            }
            $factDate = is_string($this->deadline_fact_key)
                ? ($attributes[$this->deadline_fact_key] ?? null)
                : null;

            return CalendarDate::parse($factDate);
        }

        if ($this->deadline_type === DeadlineType::PermitWindow && ($attributes['entry_mode'] ?? null) === 'd_visa') {
            return CalendarDate::parse($attributes['visa_expires_at'] ?? null);
        }

        if ($this->deadline_days === null) {
            return null;
        }

        return match ($this->deadline_type) {
            DeadlineType::DaysSinceArrival => $arrival?->copy()->addDays($this->deadline_days),
            // A missing anchor is unknown, not proof that an obligation is paused.
            DeadlineType::DaysSinceMoveIn => CalendarDate::historical($attributes['moved_in_at'] ?? null)
                ?->addDays($this->deadline_days),
            // Only an explicitly recorded entry mode selects the authored rule's window.
            DeadlineType::PermitWindow => match ($attributes['entry_mode'] ?? null) {
                'visa_free' => $arrival?->copy()->addDays($this->deadline_days),
                default => null,
            },
            // Life-event tasks anchor on the recorded event date.
            DeadlineType::DaysSinceEvent => $this->trigger_event
                ? CalendarDate::historical($attributes["{$this->trigger_event}_at"] ?? null)?->addDays($this->deadline_days)
                : null,
            default => null,
        };
    }
}
