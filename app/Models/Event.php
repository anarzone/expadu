<?php

namespace App\Models;

use App\Services\RecurrenceExpander;
use Carbon\CarbonImmutable;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

#[Fillable(['title', 'emoji', 'category', 'description', 'starts_at', 'ends_at', 'location_name', 'address', 'max_attendees', 'is_free', 'price', 'price_text', 'organiser_id', 'tags', 'is_expat_relevant', 'source', 'source_url', 'quality_score', 'title_en', 'description_en', 'source_lang', 'is_curated', 'source_uid', 'summary_en', 'tip_en', 'language', 'chips', 'relevance', 'needs_review', 'status', 'recurrence', 'recurrence_until', 'venue_id', 'verified_at'])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    protected $table = 'events';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_free' => 'boolean',
            'price' => 'decimal:2',
            'tags' => 'array',
            'is_expat_relevant' => 'boolean',
            'quality_score' => 'float',
            'chips' => 'array',
            'relevance' => 'float',
            'needs_review' => 'boolean',
            'recurrence_until' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * What any consumer (UI or composer) is allowed to see: active and
     * either relevance-approved or not yet AI-scored (legacy rows stay
     * visible until reprocessed).
     *
     * @param  Builder<Event>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('status', 'active')
            ->where(fn (Builder $q) => $q
                ->whereNull('relevance')
                ->orWhere('relevance', '>=', config('events.relevance_threshold', 0.5)));
    }

    /**
     * "What's anchorable in this window" — THE query the composer and
     * the events feed share. Expands RRULEs on the fly and returns one
     * entry per occurrence, chronological.
     *
     * @return Collection<int, array{event: Event, starts_at: CarbonImmutable, ends_at: ?CarbonImmutable}>
     */
    public static function occurringBetween(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $expander = app(RecurrenceExpander::class);

        $candidates = static::query()
            ->visible()
            ->with('venue.place')
            ->where(fn (Builder $q) => $q
                // one-off events inside the window…
                ->where(fn (Builder $oneOff) => $oneOff
                    ->whereNull('recurrence')
                    ->whereBetween('starts_at', [$from, $to]))
                // …or recurring series overlapping it
                ->orWhere(fn (Builder $recurring) => $recurring
                    ->whereNotNull('recurrence')
                    ->where('starts_at', '<=', $to)
                    ->where(fn (Builder $until) => $until
                        ->whereNull('recurrence_until')
                        ->orWhere('recurrence_until', '>=', $from))))
            ->get();

        return $candidates
            ->flatMap(function (Event $event) use ($expander, $from, $to) {
                $duration = $event->ends_at && $event->starts_at
                    ? CarbonImmutable::parse($event->starts_at)->diffInSeconds($event->ends_at)
                    : null;

                return collect($expander->expand($event, $from, $to))
                    ->map(fn (CarbonImmutable $start) => [
                        'event' => $event,
                        'starts_at' => $start,
                        'ends_at' => $duration !== null ? $start->addSeconds($duration) : null,
                    ]);
            })
            ->sortBy('starts_at')
            ->values();
    }

    /**
     * Extract latitude from PostGIS location column.
     */
    public function getLatAttribute(): ?float
    {
        if (! $this->location) {
            return null;
        }

        $result = \DB::selectOne('SELECT ST_Y(location::geometry) as lat FROM events WHERE id = ?', [$this->id]);

        return $result?->lat ? (float) $result->lat : null;
    }

    /**
     * Extract longitude from PostGIS location column.
     */
    public function getLngAttribute(): ?float
    {
        if (! $this->location) {
            return null;
        }

        $result = \DB::selectOne('SELECT ST_X(location::geometry) as lng FROM events WHERE id = ?', [$this->id]);

        return $result?->lng ? (float) $result->lng : null;
    }

    /** @return BelongsTo<User, $this> */
    public function organiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organiser_id');
    }

    /** @return BelongsTo<Venue, $this> */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_attendees')
            ->withPivot('joined_at', 'reminded_at')
            ->withTimestamps();
    }
}
