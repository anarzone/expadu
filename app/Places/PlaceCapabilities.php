<?php

namespace App\Places;

use App\Models\PlaceFactObservation;
use App\Models\Spot;
use Illuminate\Support\Collection;

/** Source-backed practical details shared by Places and Composer. */
class PlaceCapabilities
{
    public const TAGS = ['sport', 'surface', 'lit', 'covered', 'indoor', 'wheelchair', 'toilets', 'drinking_water', 'hoops', 'reservation', 'booking', 'capacity', 'operator', 'barrier', 'reservation:conditional', 'booking:conditional', 'opening_hours:conditional',
        // Venue amenities people ask about; absent tags stay unknown, never "no".
        'internet_access', 'outdoor_seating', 'cuisine', 'dog', 'diet:vegetarian', 'diet:vegan', 'diet:halal', 'diet:kosher', 'diet:gluten_free',
        // From a place's own website (provider "website"): sockets and laptop policy.
        'power_supply', 'laptops', 'laptops:conditional',
        // From people who've been there (provider "people"): quiet enough to work.
        'quiet'];

    public const ACTIVITIES = ['soccer', 'basketball', 'tennis', 'table_tennis', 'boules', 'skateboard', 'swimming', 'volleyball', 'beachvolleyball', 'badminton', 'running', 'fitness', 'climbing'];

    /** Facts where a place's own website rule (quoted) outranks OSM, and OSM outranks people. */
    public const WORK_KEYS = ['internet_access', 'power_supply', 'laptops', 'laptops:conditional', 'quiet'];

    /** @return array<string, string> */
    public static function sourceTags(array $tags): array
    {
        return collect($tags)->only(self::TAGS)
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => mb_substr(trim($value), 0, 500))->all();
    }

    /** @return list<string> */
    public static function activities(?string $sport): array
    {
        return array_values(array_unique(array_intersect(
            array_map('trim', explode(';', mb_strtolower($sport ?? ''))), self::ACTIVITIES,
        )));
    }

    /** @param Collection<int, PlaceFactObservation> $observations
     * @return array<string, array<string, mixed>>
     */
    public function resolve(Spot $spot, Collection $observations, ?string $legacySourceUrl): array
    {
        // Older ingestion versions kept these tags only on the spot. Once a
        // primary observation has the new complete field, omissions mean unknown.
        $hasNativeHistory = PlaceObservationHistory::active($spot->factObservations)->contains(fn (PlaceFactObservation $row): bool => $row->provider === $spot->source && $row->provider_record_id === $spot->source_id
            && array_key_exists('practical', $row->payload));
        $rows = $observations->filter(fn (PlaceFactObservation $row): bool => array_key_exists('practical', $row->payload))
            ->sortBy(fn (PlaceFactObservation $row) => [$row->observed_at->getTimestamp(), $row->id]);
        $legacy = $hasNativeHistory ? [] : self::sourceTags((array) $spot->tags);
        $facts = [];
        foreach (self::TAGS as $key) {
            $sources = $rows->filter(fn (PlaceFactObservation $row): bool => isset($row->payload['practical'][$key]));
            if (in_array($key, self::WORK_KEYS, true) && $sources->isNotEmpty()) {
                $facts[$key] = $this->ranked($spot, $sources, $key);

                continue;
            }
            $values = $sources->map(fn (PlaceFactObservation $row): string => $row->payload['practical'][$key])->unique();
            $last = $sources->last();
            $value = $last?->payload['practical'][$key] ?? ($rows->isEmpty() ? ($legacy[$key] ?? null) : null);
            $conflicting = $values->count() > 1;
            $facts[$key] = [
                'value' => $conflicting ? null : $value,
                'status' => $conflicting ? 'conflicting' : ($value === null ? 'unknown' : 'known'),
                'source_url' => $last?->source_url ?? ($value !== null ? $legacySourceUrl : null),
                'observed_at' => $last?->observed_at?->toIso8601String() ?? ($value !== null ? $spot->last_seen_at?->toIso8601String() : null),
                'reviewed_at' => null,
            ];
        }

        return $facts;
    }

    /**
     * Website (quoted) > the place's own source (OSM) > other providers > people.
     * The highest-ranked source decides; a lower one saying otherwise is noted
     * (disputed_by), never allowed to swap it. Two equal sources that disagree
     * leave it conflicting.
     *
     * @param  Collection<int, PlaceFactObservation>  $sources
     * @return array<string, mixed>
     */
    private function ranked(Spot $spot, Collection $sources, string $key): array
    {
        $rank = fn (PlaceFactObservation $row): int => match (true) {
            $row->provider === 'website' => 3,
            $row->provider === $spot->source && $row->provider_record_id === $spot->source_id => 2,
            $row->provider === 'people' => 0,
            default => 1,
        };
        $top = $sources->max($rank);
        $leaders = $sources->filter(fn (PlaceFactObservation $row): bool => $rank($row) === $top)
            ->sortBy(fn (PlaceFactObservation $row) => [$row->observed_at?->getTimestamp() ?? 0, $row->id]);
        $values = $leaders->map(fn (PlaceFactObservation $row): string => $row->payload['practical'][$key])->unique();
        $last = $leaders->last();
        $value = $values->count() > 1 ? null : $last->payload['practical'][$key];
        $disputedBy = $value === null ? [] : $sources->filter(fn (PlaceFactObservation $row): bool => $rank($row) < $top && $row->payload['practical'][$key] !== $value)
            ->map(fn (PlaceFactObservation $row): string => $row->provider)->unique()->values()->all();

        return [
            'value' => $value,
            'status' => $values->count() > 1 ? 'conflicting' : 'known',
            'source_url' => $last->source_url,
            'observed_at' => $last->observed_at?->toIso8601String(),
            'reviewed_at' => null,
            'provider' => $last->provider,
            // The website's own sentence, or people's count summary: what Composer and the sheet quote.
            'quote' => $value === null ? null : ($last->payload['evidence'][$key] ?? null),
            ...($disputedBy !== [] ? ['disputed_by' => $disputedBy] : []),
        ];
    }
}
