<?php

namespace App\Jobs;

use App\Enums\EventCategory;
use App\Models\Event;
use App\Services\ClassifiesEvents;
use App\Services\VenueResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The per-event half of the ingest pipeline: one AI classification
 * (stored forever — the read path makes zero AI calls), guardrails,
 * and venue resolution with the ≤50m place link. Idempotent: an
 * already-classified event is skipped.
 */
class ProcessEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2; // malformed output → one retry, then needs_review

    public int $timeout = 45;

    public function __construct(public Event $event)
    {
        $this->onConnection('redis');
    }

    public function handle(ClassifiesEvents $classifier): void
    {
        $event = $this->event->fresh();
        if (! $event || $event->summary_en !== null) {
            return;
        }

        $result = $classifier->classify($event);

        // Below the confidence floor: keep the translation but DROP the
        // chips (a missing chip is annoying, a wrong one destroys trust)
        // and queue the event for human review.
        $needsReview = $result['confidence'] < config('events.review_confidence', 0.7);

        $event->update([
            'title_en' => mb_substr($result['title_en'], 0, 500),
            'summary_en' => mb_substr($result['summary_en'], 0, 400),
            'tip_en' => $result['tip_en'] ? mb_substr($result['tip_en'], 0, 300) : null,
            'language' => $result['language'],
            'chips' => $needsReview ? [] : $result['chips'],
            'category' => (EventCategory::tryFrom($result['category']) ?? EventCategory::Other)->value,
            'relevance' => $result['relevance'],
            'needs_review' => $needsReview,
        ]);

        $this->resolveVenue($event);

        Log::info('event classified', [
            'event_id' => $event->id,
            'relevance' => $result['relevance'],
            'confidence' => $result['confidence'],
            'needs_review' => $needsReview,
        ]);
    }

    /**
     * Classification failed twice → keep the event but flag it; the
     * review queue is the safety net, not an exception trace.
     */
    public function failed(?Throwable $exception): void
    {
        Log::warning('event classification failed', [
            'event_id' => $this->event->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->event->fresh()?->update(['needs_review' => true]);
    }

    private function resolveVenue(Event $event): void
    {
        if (! $event->location_name || $event->venue_id) {
            return;
        }

        $venue = app(VenueResolver::class)->resolve(
            $event->location_name,
            $event->address,
            $event->lat,
            $event->lng,
        );

        $event->update(['venue_id' => $venue->id]);
    }
}
