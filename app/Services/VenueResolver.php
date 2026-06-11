<?php

namespace App\Services;

use App\Models\Spot;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;

/**
 * Find-or-create the venue row for an event and link it to a seeded
 * place when one sits within ~50m — the Events↔Places bridge.
 */
class VenueResolver
{
    public function resolve(string $name, ?string $address, ?float $lat, ?float $lng): Venue
    {
        $venue = Venue::query()->firstOrCreate(
            [
                'name' => mb_substr($name, 0, 200),
                'address_text' => $address ? mb_substr($address, 0, 300) : null,
            ],
            ['lat' => $lat, 'lng' => $lng],
        );

        if ($venue->lat && $venue->lng && ! $venue->place_id) {
            $place = $this->placeWithin50m($venue->name, (float) $venue->lat, (float) $venue->lng);

            if ($place) {
                $venue->update(['place_id' => $place->id, 'veedel' => $place->veedel]);
            } elseif (! $venue->veedel) {
                $venue->update(['veedel' => $this->nearestVeedel((float) $venue->lat, (float) $venue->lng)]);
            }
        }

        return $venue;
    }

    /**
     * Proximity alone mislinks (a venue 40m from an unrelated business);
     * the candidate must also share a meaningful name token.
     */
    private function placeWithin50m(string $venueName, float $lat, float $lng): ?Spot
    {
        $candidates = Spot::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->whereRaw(
                '(6371 * acos(LEAST(1, cos(radians(?)) * cos(radians(lat)) * cos(radians(lng) - radians(?)) + sin(radians(?)) * sin(radians(lat))))) <= 0.05',
                [$lat, $lng, $lat],
            )
            ->orderByRaw(
                '(6371 * acos(LEAST(1, cos(radians(?)) * cos(radians(lat)) * cos(radians(lng) - radians(?)) + sin(radians(?)) * sin(radians(lat)))))',
                [$lat, $lng, $lat],
            )
            ->limit(5)
            ->get();

        $venueTokens = $this->nameTokens($venueName);

        return $candidates->first(
            fn (Spot $spot) => array_intersect($venueTokens, $this->nameTokens($spot->name)) !== [],
        );
    }

    /**
     * @return list<string>
     */
    private function nameTokens(string $name): array
    {
        preg_match_all('/[\p{L}]{4,}/u', mb_strtolower($name), $matches);

        return $matches[0] ?? [];
    }

    private function nearestVeedel(float $lat, float $lng): ?string
    {
        return DB::table('veedels')
            ->whereNotNull('centroid_lat')
            ->orderByRaw(
                '(6371 * acos(LEAST(1, cos(radians(?)) * cos(radians(centroid_lat)) * cos(radians(centroid_lng) - radians(?)) + sin(radians(?)) * sin(radians(centroid_lat)))))',
                [$lat, $lng, $lat],
            )
            ->value('name');
    }
}
