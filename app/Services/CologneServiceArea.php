<?php

namespace App\Services;

use App\Exceptions\CologneBoundaryUnavailable;
use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CologneServiceArea
{
    public function contains(float $latitude, float $longitude): bool
    {
        $this->ensureOfficialPolygonsAvailable();

        return DB::table('veedels')
            ->whereNotNull('boundary')
            ->whereRaw(
                'ST_Covers(boundary, ST_SetSRID(ST_MakePoint(?, ?), 4326))',
                [$longitude, $latitude],
            )
            ->exists();
    }

    /** @return Builder<Event> */
    public function outsideEvents(): Builder
    {
        $this->ensureOfficialPolygonsAvailable();

        return Event::query()->whereNotNull('location')->whereRaw(
            'NOT EXISTS (SELECT 1 FROM veedels WHERE boundary IS NOT NULL AND ST_Covers(boundary, events.location::geometry))',
        );
    }

    /**
     * Bulk-load valid Cologne coordinates (the listing's, else its venue's)
     * without triggering Event's per-row PostGIS accessors.
     *
     * @param  list<int>  $eventIds
     * @return Collection<int, object{id: int, lat: float, lng: float}>
     */
    public function eventCoordinates(array $eventIds): Collection
    {
        $this->ensureOfficialPolygonsAvailable();

        if ($eventIds === []) {
            return collect();
        }

        // The listing's own point, else its venue's (events:link-venues): a koeln.de listing
        // often names only the venue, and can still be planned or answered by it.
        $point = 'COALESCE(events.location::geometry, CASE WHEN venues.lat IS NOT NULL AND venues.lng IS NOT NULL'
            .' THEN ST_SetSRID(ST_MakePoint(venues.lng::float8, venues.lat::float8), 4326) END)';

        return DB::table('events')
            ->leftJoin('venues', 'venues.id', '=', 'events.venue_id')
            ->whereIn('events.id', $eventIds)
            ->whereRaw("{$point} IS NOT NULL")
            ->whereRaw("EXISTS (SELECT 1 FROM veedels WHERE boundary IS NOT NULL AND ST_Covers(boundary, {$point}))")
            ->selectRaw("events.id AS id, ST_Y({$point}) AS lat, ST_X({$point}) AS lng")
            ->get()
            ->keyBy('id');
    }

    public function officialPolygonsAvailable(): bool
    {
        return $this->officialPolygonCount() >= (int) config('events.geocoding.expected_polygon_count', 86);
    }

    public function ensureOfficialPolygonsAvailable(): void
    {
        $available = $this->officialPolygonCount();
        $expected = (int) config('events.geocoding.expected_polygon_count', 86);

        if ($available < $expected) {
            throw CologneBoundaryUnavailable::incomplete($available, $expected);
        }
    }

    private function officialPolygonCount(): int
    {
        return DB::table('veedels')->whereNotNull('boundary')->count();
    }
}
