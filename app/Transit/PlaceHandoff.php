<?php

namespace App\Transit;

use App\Models\Spot;
use App\Places\PlaceFacts;

/**
 * A place's "Get there" hands it to Plan a trip by its id
 * (`/timetable?view=journey&place=`), never by coordinates. The point is
 * the one the place sheet routes to: its verified entrance, else its map
 * point. An alias opens as its place; an unknown, closed (inactive) or
 * access-restricted place gives nothing, and the page opens empty.
 */
final class PlaceHandoff
{
    /**
     * @return array{id: int, name: string, area: ?string, lat: float, lng: float}|null
     */
    public function for(mixed $id): ?array
    {
        if (! is_string($id) || ! ctype_digit($id) || strlen($id) > 12) {
            return null;
        }

        $spot = Spot::query()->find((int) $id);
        if ($spot?->canonical_spot_id !== null) {
            $spot = Spot::query()->find($spot->canonical_spot_id);
        }
        if ($spot === null || ! $spot->is_active || ! $spot->public_access_allowed) {
            return null;
        }

        $facts = app(PlaceFacts::class)->resolve($spot);
        $entrance = $facts['location']['entrance_point'];
        $route = $entrance['status'] === 'verified' ? $entrance : $facts['location']['map_point'];
        $lat = $route['lat'] ?? $spot->lat;
        $lng = $route['lng'] ?? $spot->lng;
        if ($lat === null || $lng === null) {
            return null;
        }

        return [
            'id' => $spot->id,
            'name' => (string) ($facts['name']['value'] ?? $spot->name),
            'area' => $spot->veedel,
            'lat' => (float) $lat,
            'lng' => (float) $lng,
        ];
    }
}
