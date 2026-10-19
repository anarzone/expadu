<?php

namespace App\Console\Commands\Events;

use App\Models\Event;
use App\Models\Venue;
use App\Services\CologneServiceArea;
use App\Services\GeocodingService;
use App\Services\VenueResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Backfill venue links for events that missed them. Venue resolution
 * normally happens inside ProcessEventJob, but historically that job
 * died on classification errors before linking, and importers only
 * re-dispatch an event when its content changes — so old events stayed
 * venue-less forever and could never inherit venue media. Pure DB work
 * (no external calls), idempotent, safe to run on a schedule.
 */
class LinkEventVenues extends Command
{
    protected $signature = 'events:link-venues
        {--limit=1000 : Max events to link per run}
        {--geocode=60 : Max venue geocoding lookups per run (0 = skip)}';

    protected $description = 'Link venue-less events to venues by location name (heals pre-classification-failure orphans)';

    public function handle(VenueResolver $venues): int
    {
        $linked = 0;
        $failed = 0;

        Event::query()
            ->whereNull('venue_id')
            ->whereNotNull('location_name')
            ->where('location_name', '!=', '')
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function (Event $event) use ($venues, &$linked, &$failed) {
                try {
                    $venue = $venues->resolve(
                        $event->location_name,
                        $event->address,
                        $event->lat,
                        $event->lng,
                    );
                    $event->update(['venue_id' => $venue->id]);
                    $linked++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->warn("  event {$event->id}: {$e->getMessage()}");
                }
            });

        $this->info("Linked {$linked} event(s) to venues".($failed > 0 ? ", {$failed} failed" : '').'.');

        $this->info('Healed coordinates for '.$this->healCoordinates($venues).' venue(s).');

        $this->info('Geocoded '.$this->geocodeVenues($venues).' venue(s).');

        return self::SUCCESS;
    }

    /**
     * Coordinate-less venues can't be photographed (both photo strategies
     * need a location to verify against) and never got the place/veedel
     * link. Borrow the trusted coordinates of one of their own events —
     * resolve() matches the same (name, address) row and runs its full
     * update path (coords, ≤50m place link, veedel).
     */
    private function healCoordinates(VenueResolver $venues): int
    {
        $healed = 0;

        Venue::query()
            ->whereNull('lat')
            ->orderBy('id')
            ->get()
            ->each(function (Venue $venue) use ($venues, &$healed) {
                $event = $venue->events()->whereNotNull('location')->first();
                if (! $event || $event->lat === null || $event->lng === null) {
                    return;
                }

                try {
                    $venues->resolve($venue->name, $venue->address_text, $event->lat, $event->lng);
                    $healed++;
                } catch (\Throwable $e) {
                    $this->warn("  venue {$venue->id}: {$e->getMessage()}");
                }
            });

        return $healed;
    }

    /**
     * Last resort for venues whose events carry no coordinates either
     * (the ingest-era enrichment never geocoded them): geocode the venue
     * itself — address first, name as fallback — accepting only results
     * inside the Cologne service area, same guard as the event path.
     *
     * A venue the geocoder cannot place is remembered and retried with
     * exponential backoff (1, 2, 4 … days, capped at a week) — otherwise the
     * same unfound venues re-use the per-run quota every hour and starve the
     * ones behind them.
     */
    private function geocodeVenues(VenueResolver $venues): int
    {
        $limit = (int) $this->option('geocode');
        if ($limit <= 0) {
            return 0;
        }

        $geocoder = app(GeocodingService::class);
        $serviceArea = app(CologneServiceArea::class);
        $geocoded = 0;

        Venue::query()
            ->whereNull('lat')
            ->lazyById(200)
            ->reject(fn (Venue $venue): bool => $this->geocodeBackoffActive($venue))
            ->take($limit)
            ->each(function (Venue $venue) use ($venues, $geocoder, $serviceArea, &$geocoded) {
                $query = trim((string) ($venue->address_text ?: $venue->name));
                if ($query === '') {
                    $this->rememberGeocodeMiss($venue);

                    return;
                }

                try {
                    $results = $geocoder->search("{$query}, Köln");
                    $hit = collect($results)->first(fn (array $candidate): bool => isset($candidate['lat'], $candidate['lng'])
                        && $serviceArea->contains((float) $candidate['lat'], (float) $candidate['lng']));

                    if ($hit !== null) {
                        $venues->resolve($venue->name, $venue->address_text, (float) $hit['lat'], (float) $hit['lng']);
                        Cache::forget($this->geocodeMissKey($venue));
                        $geocoded++;
                    } else {
                        $this->rememberGeocodeMiss($venue);
                    }
                } catch (\Throwable $e) {
                    $this->rememberGeocodeMiss($venue);
                    $this->warn("  geocode venue {$venue->id}: {$e->getMessage()}");
                }

                usleep(300_000); // stay polite to the public photon instance
            });

        return $geocoded;
    }

    private function geocodeMissKey(Venue $venue): string
    {
        return "events:link-venues:geocode-miss:{$venue->id}";
    }

    private function geocodeBackoffActive(Venue $venue): bool
    {
        $miss = Cache::get($this->geocodeMissKey($venue));

        return is_array($miss) && ($miss['retry_after'] ?? 0) > now()->getTimestamp();
    }

    private function rememberGeocodeMiss(Venue $venue): void
    {
        $key = $this->geocodeMissKey($venue);
        $previous = Cache::get($key);
        $misses = (is_array($previous) ? (int) ($previous['misses'] ?? 0) : 0) + 1;

        // 1, 2, 4, 7, 7 … days. The record outlives the longest wait so the
        // miss count survives to the next attempt.
        $days = min(2 ** ($misses - 1), 7);

        Cache::put($key, [
            'misses' => $misses,
            'retry_after' => now()->addDays($days)->getTimestamp(),
        ], now()->addDays(30));
    }
}
