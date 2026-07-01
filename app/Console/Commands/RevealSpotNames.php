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

    protected $description = 'Give duplicate generic spots (Bolzplatz, Spielplatz, …) distinct names via their containing park, else the nearest street; --geocode also prunes spots outside Köln / Leverkusen / Bonn';

    /** We only carry these three cities for now (majorly Köln). */
    private const ALLOWED_CITIES = ['köln', 'koeln', 'cologne', 'leverkusen', 'bonn'];

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

        // Pass 2 — reverse-geocode the rest. One call yields BOTH the nearest
        // street and the municipality, so we prune anything outside our three
        // cities and only anchor to a *real* street (never the nearest café or
        // charging station). Throttled; skips gracefully when the geocoder is
        // unreachable (e.g. locally).
        //
        // chunkById walks forward by id: anchored/pruned rows drop out of the
        // set and kept-bare rows sit below the cursor, so nothing is re-fetched
        // and memory stays bounded over the (thousands of) points — a plain
        // ->get() OOM-killed the process mid-run.
        $limit = (int) $this->option('limit');
        $geocoded = 0;
        $pruned = 0;
        $keptBare = 0;
        $skipped = 0;
        $processed = 0;

        Spot::whereIn('name', $labels)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderBy('id')
            ->chunkById(100, function ($spots) use ($routes, $limit, &$geocoded, &$pruned, &$keptBare, &$skipped, &$processed) {
                foreach ($spots as $spot) {
                    if ($limit > 0 && $processed >= $limit) {
                        return false;
                    }
                    $processed++;

                    try {
                        $place = $routes->reverseGeocode(new GeoPoint((float) $spot->lat, (float) $spot->lng));
                    } catch (\Throwable) {
                        $skipped++;

                        continue;
                    }
                    if ($place === null) {
                        $skipped++;

                        continue;
                    }

                    usleep(200_000); // ~5/s — be kind to the geocoder

                    // Scope: keep only Köln / Leverkusen / Bonn; drop confirmed other towns.
                    if ($this->outsideAllowedCity($place->municipality)) {
                        $spot->delete();
                        $pruned++;

                        continue;
                    }

                    $street = $this->streetFrom($place->name);
                    if ($street === null) {
                        $keptBare++;

                        continue;
                    }

                    $spot->update(['name' => "{$spot->name} · {$street}"]);
                    $geocoded++;
                }

                gc_collect_cycles();

                return true;
            });

        $this->info("Anchored to a street: {$geocoded}");
        $this->info("Pruned (outside Köln/Leverkusen/Bonn): {$pruned}");
        $this->line("Kept bare (no street-like anchor): {$keptBare}  ·  skipped/unreachable: {$skipped}");

        return self::SUCCESS;
    }

    /**
     * True when the municipality is a known town OTHER than our three cities.
     * Unknown/empty → false: never prune on uncertainty.
     */
    private function outsideAllowedCity(?string $municipality): bool
    {
        $m = mb_strtolower(trim((string) $municipality));
        if ($m === '') {
            return false;
        }

        foreach (self::ALLOWED_CITIES as $city) {
            if (str_contains($m, $city)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A usable street anchor from a reverse-geocode label, or null when the
     * label is a POI (café, school, charging station…) rather than a street —
     * better to stay bare than to invent "· Eiscafe Linizio".
     */
    private function streetFrom(?string $raw): ?string
    {
        $label = trim(explode(',', (string) $raw)[0]);                          // drop ", 50765 Köln"
        $label = trim((string) preg_replace('/\s+\d+\s*[a-z]?$/i', '', $label)); // drop house number

        if ($label === '' || $this->isPoi($label) || ! $this->looksLikeStreet($label)) {
            return null;
        }

        return $label;
    }

    /**
     * Reject institutional / POI labels even when they end in a street-ish word
     * (e.g. "Kath. Grundschule Mengenicher Straße"). Better bare than mislabelled.
     */
    private function isPoi(string $s): bool
    {
        return (bool) preg_match(
            '/(schule|gymnasium|kindergarten|kita|sporthalle|turnhalle|hallenbad|freibad|schwimmbad|kirche|kapelle|friedhof|eiscafe|caf[eé]|restaurant|imbiss|hotel|krankenhaus|klinik|apotheke|supermarkt|tankstelle|ladestation|kiosk|spielplatz|bolzplatz|sportplatz|parkplatz|wertstoff|rathaus|bibliothek|museum|stadion)/iu',
            $s,
        );
    }

    /**
     * German street heuristic: a "…straße/…weg/…gasse" suffix or an "Am/An der…"
     * prefix. Deliberately excludes "…platz" — near a Spiel-/Bolzplatz the
     * geocoder often returns another square/playground, not a street.
     */
    private function looksLikeStreet(string $s): bool
    {
        if (preg_match('/^(am|an|auf|bei|hinter|im|in|unter|vor|zum|zur)\s/iu', $s)) {
            return true;
        }

        return (bool) preg_match(
            '/(stra(ß|ss)e|str\.?|weg|gasse|allee|ring|damm|pfad|kamp|hof|ufer|steig|chaussee|zeile|winkel|bogen|graben|wall|gracht)$/iu',
            $s,
        );
    }
}
