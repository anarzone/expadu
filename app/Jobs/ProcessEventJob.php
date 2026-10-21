<?php

namespace App\Jobs;

use App\Enums\EventCategory;
use App\Models\Event;
use App\Places\InterestTaxonomy;
use App\Services\AnthropicEventClassifier;
use App\Services\ClassificationRefused;
use App\Services\ClassifiesEvents;
use App\Services\EventEnrichmentService;
use App\Services\VenueResolver;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The per-event half of the ingest pipeline: deterministic enrichment,
 * venue resolution with the ≤50m place link, and optional AI classification
 * stored for zero-AI-call reads. Idempotent: an already-classified event is
 * skipped.
 */
class ProcessEventJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const MAX_TIMEOUT_SECONDS = 900;

    public int $tries = 2; // malformed output → one retry, then needs_review

    public int $timeout = 45;

    public int $uniqueFor = 3600;

    public function __construct(public Event $event)
    {
        // Its own queue and worker: a job can run for up to 15 minutes and
        // must not hold weather refreshes, mail or media checks behind it.
        $this->onQueue('llm');

        $translationChunks = (int) ceil(mb_strlen((string) $event->description) / 2000);
        $this->timeout = min(self::MAX_TIMEOUT_SECONDS, max(45, 30 + ($translationChunks * 35)));
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

        if (! config('events.classification.enabled')) {
            return;
        }

        $inputHash = $this->inputHash($event);
        if ($this->translationsAreComplete($event)
            && ($event->classification_input_hash === null || hash_equals($event->classification_input_hash, $inputHash))) {
            return;
        }

        // No API key: every call would 401. Leave the event untranslated
        // for the backfill to pick up once a key is set.
        if (! $classifier->isConfigured()) {
            return;
        }

        // A refusal repeats on the same input, and the hourly backfill would
        // re-queue (and re-bill) it forever; skip until the source changes.
        $refusalKey = 'events:classification-refused:'.$event->id;
        if (Cache::get($refusalKey) === $inputHash) {
            return;
        }

        try {
            $result = $classifier->classify($event);
        } catch (ClassificationRefused $exception) {
            Cache::put($refusalKey, $inputHash, now()->addDays(30));
            $event->update(['needs_review' => true]);
            Log::warning('event classification refused', [
                'event_id' => $event->id,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

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
            // Below the confidence floor the category stays the source's, like the chips.
            'category' => $needsReview ? $event->category : (EventCategory::tryFrom($result['category']) ?? EventCategory::Other)->value,
            'relevance' => $result['relevance'],
            'needs_review' => $needsReview,
            'classification_input_hash' => $inputHash,
        ]);

        // The event row keeps only the current values; keep each run's model
        // and confidence as history so classifications can be audited and
        // compared across models.
        $category = (EventCategory::tryFrom($result['category']) ?? EventCategory::Other)->value;
        DB::table('event_enrichments')->insert([
            'event_id' => $event->id,
            'producer' => class_basename($classifier),
            'model' => $classifier instanceof AnthropicEventClassifier ? config('services.anthropic.model') : null,
            'confidence' => round(max(0.0, min(1.0, (float) $result['confidence'])), 3),
            'relevance' => round(max(0.0, min(1.0, (float) $result['relevance'])), 3),
            'category' => $category,
            'chips' => json_encode(array_values((array) $result['chips'])),
            'interests' => json_encode(app(InterestTaxonomy::class)->forEventCategory($category)),
            'needs_review' => $needsReview,
            'input_hash' => $inputHash,
            'created_at' => now(),
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
