<?php

namespace App\Transit;

use App\Models\Spot;
use App\Places\PlaceFacts;

/**
 * A place's "Get there" hands it to Plan a trip by its id
 * (`/timetable?view=journey&place=`), never by coordinates. The point is
 * the one the place sheet routes to: its verified entrance, else its map
 * point. It's named as the sheet names it ("Football pitch in Merkenich",
 * never the importer's "Bolzplatz"); a large park's part (`part`, a Veedel)
 * reads "Äußerer Grüngürtel (Sülz)" and leads to that part's facilities. An
 * alias opens as its place; an unknown, closed (inactive) or
 * access-restricted place gives nothing, and the page opens empty.
 */
final class PlaceHandoff
{
    /**
     * @return array{id: int, name: string, area: ?string, part: ?string, lat: float, lng: float}|null
     */
    public function for(mixed $id, mixed $part = null): ?array
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

        $name = (string) ($facts['display_name'] ?? $facts['name']['value'] ?? $spot->name);
        $there = is_string($part) && mb_strlen($part) <= 60 ? $this->part($spot, $part) : null;

        return [
            'id' => $spot->id,
            'name' => $there === null ? $name : "{$name} ({$there['part']})",
            'area' => $there['part'] ?? $spot->veedel,
            'part' => $there['part'] ?? null,
            'lat' => (float) ($there['lat'] ?? $lat),
            'lng' => (float) ($there['lng'] ?? $lng),
        ];
    }

    /**
     * A large park's part in one Veedel: the facility in the middle of that
     * part's, so the way leads there and not to the park's middle.
     *
     * @return array{part: string, lat: float, lng: float}|null
     */
    private function part(Spot $park, string $veedel): ?array
    {
        $members = Spot::query()
            ->where('parent_spot_id', $park->id)
            ->where('veedel', $veedel)
            ->where('is_active', true)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['lat', 'lng']);
        if ($members->isEmpty()) {
            return null;
        }

        $lat = $members->avg(fn (Spot $m) => (float) $m->lat);
        $lng = $members->avg(fn (Spot $m) => (float) $m->lng);
        $middle = $members->sortBy(fn (Spot $m) => ((float) $m->lat - $lat) ** 2 + (((float) $m->lng - $lng) * 0.63) ** 2)->first();

        return ['part' => $veedel, 'lat' => (float) $middle->lat, 'lng' => (float) $middle->lng];
    }
}
