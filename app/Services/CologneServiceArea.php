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
     * Bulk-load valid Cologne coordinates without triggering Event's per-row
     * PostGIS accessors.
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

        return DB::table('events')
            ->whereIn('id', $eventIds)
            ->whereNotNull('location')
            ->whereRaw('EXISTS (SELECT 1 FROM veedels WHERE boundary IS NOT NULL AND ST_Covers(boundary, events.location::geometry))')
            ->selectRaw('id, ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng')
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
