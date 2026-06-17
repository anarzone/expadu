<?php

namespace App\Home;

use App\Models\Event;
use App\Support\EventOccurrencePresenter;
use Carbon\CarbonImmutable;

/**
 * Picks the single "don't miss" event for the right-panel hero widget.
 * Draws from the same curated `Event::occurringBetween()` + `visible()`
 * set as the Events page (so the two surfaces never disagree) and returns
 * it shaped exactly like an /api/events occurrence, so the widget reuses
 * the existing client components verbatim.
 */
class FeaturedEvent
{
    public function __construct(private EventOccurrencePresenter $presenter) {}

    /**
     * @return array<string, mixed>|null
     */
    public function forToday(): ?array
    {
        $now = CarbonImmutable::now('Europe/Berlin');

        $best = Event::occurringBetween($now->startOfDay(), $now->endOfDay())
            // Still catchable today — drop anything that has already ended.
            ->filter(fn (array $o) => ($o['ends_at'] ?? $o['starts_at'])->greaterThanOrEqualTo($now))
            // Deterministic hero pick via stable chained sorts (PHP 8 sorts are
            // stable): soonest first, then "has a summary", then "has a photo"
            // last so a photographed event wins — it makes the richest card.
            ->sortBy(fn (array $o) => $o['starts_at']->getTimestamp())
            ->sortBy(fn (array $o) => ($o['event']->summary_en || $o['event']->description_en) ? 0 : 1)
            ->sortBy(fn (array $o) => $o['event']->venue?->place?->photo_url ? 0 : 1)
            ->first();

        return $best
            ? $this->presenter->present($best['event'], $best['starts_at'], $best['ends_at'])
            : null;
    }
}
