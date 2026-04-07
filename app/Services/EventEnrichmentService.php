<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Facades\DB;

class EventEnrichmentService
{
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

        $query = $event->location_name ?? $event->address;
        if (! $query || in_array(mb_strtolower($query), ['cologne', 'köln', ''], true)) {
            return;
        }

        // Add "Köln" for better geocoding results
        $searchQuery = $query.', Köln';

        try {
            $geocoder = app(GeocodingService::class);
            $results = $geocoder->search($searchQuery);

            if (! empty($results) && isset($results[0]['lat'], $results[0]['lng'])) {
                $lat = $results[0]['lat'];
                $lng = $results[0]['lng'];
                \DB::statement(
                    'UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
                    [$lng, $lat, $event->id]
                );
            }
        } catch (\Exception $e) {
            // Silent fail — geocoding is best-effort
        }
    }

    /**
     * Enrich all events that need it.
     */
    public function enrichAll(int $limit = 100): int
    {
        $events = Event::where('quality_score', '<', 0.3)
            ->orWhereNull('tags')
            ->orderBy('starts_at')
            ->limit($limit)
            ->get();

        $count = 0;
        foreach ($events as $event) {
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
            ->select('title', DB::raw('DATE(starts_at) as event_date'), DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as cnt'))
            ->groupBy('title', DB::raw('DATE(starts_at)'))
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $deleted = 0;
        foreach ($duplicates as $dup) {
            $deleted += Event::where('title', $dup->title)
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
