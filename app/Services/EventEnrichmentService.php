<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EventEnrichmentService
{
    public function __construct(private readonly CologneServiceArea $serviceArea) {}

    /**
     * Enrich a single event with tags, expat relevance, and quality score.
     */
    public function enrichEvent(Event $event): void
    {
        $event->tags = $this->assignTags($event);
        $event->is_expat_relevant = $this->computeExpatRelevance($event);
        $this->geocodeEvent($event);
        $event->quality_score = $this->computeQualityScore($event);
        $event->save();
    }

    /**
     * Geocode event venue if location column is empty and we have a venue name.
     */
    protected function geocodeEvent(Event $event): void
    {
        // Skip if already geocoded
        if ($event->location) {
            return;
        }

        $query = $event->address ?: $event->location_name;
        if (! $query || in_array(mb_strtolower($query), ['cologne', 'köln', ''], true)) {
            return;
        }

        // Add "Köln" for better geocoding results
        $searchQuery = $query.', Köln';

        try {
            $geocoder = app(GeocodingService::class);
            $results = $geocoder->search($searchQuery);

            $result = collect($results)->first(fn (array $candidate): bool => isset($candidate['lat'], $candidate['lng'])
                && $this->serviceArea->contains((float) $candidate['lat'], (float) $candidate['lng'])
            );

            if ($result !== null) {
                $lat = $result['lat'];
                $lng = $result['lng'];
                \DB::statement(
                    'UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
                    [$lng, $lat, $event->id]
                );
            } elseif ($results !== []) {
                $event->needs_review = true;
            }
        } catch (\Exception $exception) {
            DB::statement('UPDATE events SET location = NULL, venue_id = NULL, needs_review = TRUE WHERE id = ?', [$event->id]);
            Log::warning('event geocoding failed', [
                'event_id' => $event->id,
                'query' => $searchQuery,
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Enrich all events that need it.
     */
    public function enrichAll(int $limit = 100): int
    {
        $events = Event::query()
            ->where('status', 'active')
            ->where(fn (Builder $future): Builder => $future->whereNull('ends_at')->where('starts_at', '>=', now())
                ->orWhere('ends_at', '>=', now()))
            ->where(function (Builder $query): void {
                $query->where('quality_score', '<', 0.3)
                    ->orWhereNull('tags')
                    ->orWhereNull('location');
            })
            ->orderBy('starts_at')
            ->limit($limit * 5)
            ->get();

        $count = 0;
        foreach ($events as $event) {
            if ($count >= $limit) {
                break;
            }
            if (! Cache::add("events:enrichment:{$event->id}", true, now()->addHours(6))) {
                continue;
            }
            $this->enrichEvent($event);
            $count++;
        }

        return $count;
    }

    /**
     * Remove duplicate events (similar title + same date).
     */
    public function deduplicateEvents(): int
    {
        $duplicates = DB::table('events')
            ->whereNull('source_uid')
            ->select('source', 'title', DB::raw('DATE(starts_at) as event_date'), DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as cnt'))
            ->groupBy('source', 'title', DB::raw('DATE(starts_at)'))
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $deleted = 0;
        foreach ($duplicates as $dup) {
            $deleted += Event::where('title', $dup->title)
                ->where('source', $dup->source)
                ->whereDate('starts_at', $dup->event_date)
                ->where('id', '!=', $dup->keep_id)
                ->delete();
        }

        return $deleted;
    }

    /**
     * Assign tags based on title, description, and category.
     *
     * @return string[]
     */
    protected function assignTags(Event $event): array
    {
        // Start from any tags already stored (e.g. source category names from scraper)
        $tags = $event->tags ?? [];

        // Add factual time-based tags derived from the event date — no guessing
        if ($event->starts_at) {
            $hour = $event->starts_at->hour;
            if ($hour >= 18) {
                $tags[] = 'evening';
            } elseif ($hour < 12) {
                $tags[] = 'morning';
            }
            $dow = $event->starts_at->dayOfWeek;
            if ($dow === 0 || $dow === 6) {
                $tags[] = 'weekend';
            }
        }

        if ($event->is_free) {
            $tags[] = 'free';
        }

        // Context tags (english-friendly, outdoor, family, etc.) are intentionally
        // left to LLM enrichment (Layer 2) — regex guessing produces false positives.

        return array_values(array_unique($tags));
    }

    /**
     * Determine if event is relevant to expats.
     */
    protected function computeExpatRelevance(Event $event): bool
    {
        $text = mb_strtolower(($event->title ?? '').' '.($event->description ?? ''));

        return (bool) preg_match('/expat|international|english|language exchange|tandem|stammtisch|newcomer|immigrant|ausländer|integration/i', $text);
    }

    /**
     * Compute quality score 0.0-1.0.
     */
    protected function computeQualityScore(Event $event): float
    {
        $score = 0.0;

        // Has specific venue (not generic "Cologne")
        if ($event->location_name && ! in_array(mb_strtolower($event->location_name), ['cologne', 'köln', ''])) {
            $score += 0.25;
        }

        // Has address with detail
        if ($event->address && ! in_array(mb_strtolower($event->address), ['cologne', 'köln', ''])) {
            $score += 0.2;
        }

        // Has description
        if ($event->description && mb_strlen($event->description) > 20) {
            $score += 0.2;
        }

        // Has proper time (not midnight which suggests missing data)
        if ($event->starts_at && $event->starts_at->hour > 0) {
            $score += 0.15;
        }

        // Links back to source
        if ($event->source_url) {
            $score += 0.1;
        }

        // Price is known (either confirmed free or has price text)
        if ($event->price_text || $event->is_free) {
            $score += 0.1;
        }

        return min(1.0, $score);
    }
}
