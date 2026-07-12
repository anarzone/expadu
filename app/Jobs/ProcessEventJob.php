<?php

namespace App\Jobs;

use App\Enums\EventCategory;
use App\Models\Event;
use App\Services\ClassifiesEvents;
use App\Services\EventEnrichmentService;
use App\Services\VenueResolver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
class ProcessEventJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2; // malformed output → one retry, then needs_review

    public int $timeout = 45;

    public int $uniqueFor = 3600;

    public function __construct(public Event $event)
    {
        $translationChunks = (int) ceil(mb_strlen((string) $event->description) / 2000);
        $this->timeout = max(45, 30 + ($translationChunks * 35));
    }

    public function handle(ClassifiesEvents $classifier): void
    {
        $event = $this->event->fresh();
        if (! $event) {
            return;
        }

        app(EventEnrichmentService::class)->enrichEvent($event);
        $event->refresh();
        $this->resolveVenue($event);

        $inputHash = $this->inputHash($event);
        if ($this->translationsAreComplete($event)
            && ($event->classification_input_hash === null || hash_equals($event->classification_input_hash, $inputHash))) {
            return;
        }

        $result = $classifier->classify($event);

        // Below the confidence floor: keep the translation but DROP the
        // chips (a missing chip is annoying, a wrong one destroys trust)
        // and queue the event for human review.
        $needsReview = $result['confidence'] < config('events.review_confidence', 0.7);

        $event->update([
            'title_en' => mb_substr($result['title_en'], 0, 500),
            'description_en' => $result['description_en'],
            'summary_en' => mb_substr($result['summary_en'], 0, 400),
            'tip_en' => $result['tip_en'] ? mb_substr($result['tip_en'], 0, 300) : null,
            'language' => $result['language'],
            'chips' => $needsReview ? [] : $result['chips'],
            'category' => (EventCategory::tryFrom($result['category']) ?? EventCategory::Other)->value,
            'relevance' => $result['relevance'],
            'needs_review' => $needsReview,
            'classification_input_hash' => $inputHash,
        ]);

        $this->resolveVenue($event);

        Log::info('event classified', [
            'event_id' => $event->id,
            'relevance' => $result['relevance'],
            'confidence' => $result['confidence'],
            'needs_review' => $needsReview,
        ]);
    }

    public function uniqueId(): string
    {
        return 'event:'.$this->event->getKey();
    }

    private function inputHash(Event $event): string
    {
        return hash('sha256', json_encode([
            'version' => 1, 'title' => $event->title, 'description' => $event->description,
            'starts_at' => $event->starts_at?->toIso8601String(), 'ends_at' => $event->ends_at?->toIso8601String(),
            'venue' => $event->location_name, 'address' => $event->address,
            'price' => $event->price_text, 'is_free' => $event->is_free,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function translationsAreComplete(Event $event): bool
    {
        return $event->title_en !== null
            && $event->summary_en !== null
            && (blank($event->description) || $event->description_en !== null);
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
