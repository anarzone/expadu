<?php

namespace App\Console\Commands;

use App\Models\Spot;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use Illuminate\Console\Command;

/**
 * Unnamed OSM facilities fall back to a bare German type word (Bolzplatz,
 * Spielplatz, …), so hundreds share one name and read as fake. This gives each
 * a distinct, locatable name by anchoring it to its containing park (already
 * computed in spots.park_name by parks:import-areas), and — with --geocode — to
 * its nearest street otherwise.
 *
 * Only spots whose name is STILL a bare label are touched, so it never rewrites
 * a real OSM name or double-anchors, and is safe to re-run.
 */
class RevealSpotNames extends Command
{
    protected $signature = 'spots:reveal-names
        {--geocode : reverse-geocode a nearest-street anchor for spots not inside a park (needs MOTIS — staging/prod)}
        {--limit=0 : cap the number of geocode lookups this run (0 = no cap)}';

    protected $description = 'Give duplicate generic spots (Bolzplatz, Spielplatz, …) distinct names via their containing park, else the nearest street';

    public function handle(RouteService $routes): int
    {
        $labels = array_values(ImportOsmSpots::FALLBACK_LABELS);

        // Pass 1 — park containment. spots.park_name is already stamped by
        // parks:import-areas (ST_Contains), so this is a pure DB rename.
        $anchored = 0;
        Spot::whereIn('name', $labels)
            ->whereNotNull('park_name')
            ->where('park_name', '!=', '')
            ->each(function (Spot $spot) use (&$anchored) {
                $spot->update(['name' => "{$spot->name} · {$spot->park_name}"]);
                $anchored++;
            });
        $this->info("Anchored to a park: {$anchored}");

        $remaining = Spot::whereIn('name', $labels)->count();

        if (! $this->option('geocode')) {
            $this->line("Still bare (re-run with --geocode for a street anchor): {$remaining}");

            return self::SUCCESS;
        }

        // Pass 2 — nearest street for everything not in a park. Throttled; skips
        // gracefully when the geocoder is unreachable (e.g. locally).
        $limit = (int) $this->option('limit');
        $query = Spot::whereIn('name', $labels)->whereNotNull('lat')->whereNotNull('lng');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $geocoded = 0;
        $skipped = 0;
        foreach ($query->get() as $spot) {
            $street = $this->nearestStreet($routes, (float) $spot->lat, (float) $spot->lng);
            if ($street === null) {
                $skipped++;

                continue;
            }

            $spot->update(['name' => "{$spot->name} · {$street}"]);
            $geocoded++;
            usleep(200_000); // ~5/s — be kind to the geocoder
        }
        $this->info("Anchored to a street: {$geocoded}  (skipped/unreachable: {$skipped})");

        return self::SUCCESS;
    }

    /**
     * The nearest street name for a point, or null when the geocoder is
     * unreachable or returns nothing usable.
     */
    private function nearestStreet(RouteService $routes, float $lat, float $lng): ?string
    {
        try {
            $place = $routes->reverseGeocode(new GeoPoint($lat, $lng));
        } catch (\Throwable) {
            return null;
        }

        $raw = trim((string) ($place?->name ?? ''));
        if ($raw === '') {
            return null;
        }

        // Keep just the street: drop a trailing ", 50765 Köln" and any house number.
        $street = trim(explode(',', $raw)[0]);
        $street = trim((string) preg_replace('/\s+\d+\s*[a-z]?$/i', '', $street));

        return $street !== '' ? $street : null;
    }
}
