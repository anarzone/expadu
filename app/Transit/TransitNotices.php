<?php

namespace App\Transit;

use App\Models\CityNews;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Official notices from the feeds Travel already reads, kept in the notices
 * store (city_news, category transit) beside KVB's: VRS's TRIAS situations
 * and DELFI's alerts. Each is one row by its feed's own id, written again
 * only when its text, scope or validity changes, so transit:brief-notices
 * briefs each new or changed text once (not per rider).
 *
 * A notice without an end stays open only while its feed still carries it:
 * its end is pushed on each sighting, so one that's withdrawn closes itself.
 */
final class TransitNotices
{
    /**
     * These feeds' notices show on Travel's boards. They aren't pushed
     * (new-disruption alerts, planned-trip watching) until Alerts is built
     * for them (the delays plan's D4).
     */
    public const SOURCES = ['vrs-trias', 'delfi'];

    /** How long a notice with no end of its own stays open after it was last seen. */
    private const SEEN_MINUTES = 30;

    /**
     * @param  string  $key  the feed's id, kept as the row's source_url ("trias:situation:…")
     * @param  list<string>  $lines  line names as riders see them ("18")
     * @param  list<string>  $stops  stop names as the feed gives them
     * @param  list<string>  $trips  the feed's trip ids it's attached to
     */
    public function remember(
        string $key,
        string $source,
        string $title,
        ?string $details,
        array $lines,
        array $stops = [],
        array $trips = [],
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $until = null,
    ): void {
        $title = trim($title);
        $details = trim((string) $details);
        if ($title === '' && $details === '') {
            return;
        }
        if ($title === '') {
            // A notice with only its text: its first line is its title.
            $title = trim(strtok($details, "\n") ?: $details);
        }

        // Open while seen: ten-minute steps, so a notice isn't rewritten on every read.
        $seen = CarbonImmutable::now()->addMinutes(self::SEEN_MINUTES)->ceilMinutes(10);
        $until ??= $seen;

        $fields = [
            'title' => mb_substr($title, 0, 255),
            'summary' => $details !== '' && $details !== $title ? $details : null,
            'source' => $source,
            'category' => 'transit',
            'relevance' => 'transit',
            'affected_lines' => array_values(array_unique($lines)),
            'affected_stops' => array_values(array_unique($stops)) ?: null,
            'affected_trips' => array_values(array_unique($trips)) ?: null,
            'severity' => 'moderate',
            'expires_at' => $until,
        ];
        $fingerprint = sha1(json_encode([$fields, $from?->toIso8601String(), $until->toIso8601String()]));
        if (Cache::get("transit-notice:{$key}") === $fingerprint) {
            return;
        }

        $notice = CityNews::query()->firstOrNew(['source_url' => $key]);
        $notice->fill($fields);
        // When it started: as the feed says, else when it was first seen.
        $notice->published_at = $from ?? $notice->published_at ?? CarbonImmutable::now();
        $notice->save();

        Cache::put("transit-notice:{$key}", $fingerprint, now()->addMinutes(self::SEEN_MINUTES));
    }
}
