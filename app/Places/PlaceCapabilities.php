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
        'internet_access', 'outdoor_seating', 'cuisine', 'dog', 'diet:vegetarian', 'diet:vegan', 'diet:halal', 'diet:kosher', 'diet:gluten_free'];

    public const ACTIVITIES = ['soccer', 'basketball', 'tennis', 'table_tennis', 'boules', 'skateboard', 'swimming', 'volleyball', 'beachvolleyball', 'badminton', 'running', 'fitness', 'climbing'];

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
}
