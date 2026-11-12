<?php

namespace App\Transit;

use App\Composer\TravelEstimator;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Place;
use Illuminate\Support\Facades\Log;

/**
 * The way each line runs from a station to its end, along the tracks and through every stop, from the
 * routing engine (our MOTIS, else Transitous): the timetable knows the stops but not the tracks between
 * them, and a line drawn stop to stop cuts through buildings. Best effort: an engine that doesn't answer
 * leaves the map drawn through the stops.
 */
class LinePaths
{
    /** A trip's stop this close to the station is the station. */
    private const AT_STATION_KM = 0.35;

    /** At most this many runs are read for one station. */
    private const MAX_TRIPS = 16;

    public function __construct(
        private readonly MotisAdapter $motis,
        private readonly TransitousAdapter $transitous,
        private readonly TravelEstimator $distance,
    ) {}

    /**
     * Each run leaving the station, cut at it: the stops and track from here to where it ends.
     *
     * @return list<array{line: string, stops: list<array{name: string, lat: float, lng: float}>, points: list<array{0: float, 1: float}>}>
     */
    public function from(string $name, float $lat, float $lng): array
    {
        foreach ($this->engines() as $source => $engine) {
            try {
                return $this->read($engine->withTimeBudget(8.0), $name, new GeoPoint($lat, $lng));
            } catch (\Throwable $e) {
                Log::info("{$source} line paths failed", ['error' => $e->getMessage()]);
            }
        }

        return [];
    }

    /** @return array<string, TransitousAdapter> */
    private function engines(): array
    {
        $engines = [];
        if (filled(config('services.motis.url'))) {
            $engines['motis'] = $this->motis;
        }
        if (filled(config('services.transitous.url'))) {
            $engines['transitous'] = $this->transitous;
        }

        return $engines;
    }

    /**
     * @return list<array{line: string, stops: list<array{name: string, lat: float, lng: float}>, points: list<array{0: float, 1: float}>}>
     */
    private function read(TransitousAdapter $engine, string $name, GeoPoint $at): array
    {
        $stop = collect($engine->geocode($name, $at))
            ->first(fn (Place $place) => $place->stopId !== null
                && $this->distance->haversineKm($at->lat, $at->lng, $place->point->lat, $place->point->lng) <= self::AT_STATION_KM);
        if ($stop === null) {
            return [];
        }

        $ways = [];
        foreach (array_slice($engine->tripsAt((string) $stop->stopId), 0, self::MAX_TRIPS) as $run) {
            $path = $engine->tripPath($run['trip']);
            if ($path === null) {
                continue;
            }
            $cut = $this->fromStation($path, $stop->point);
            if ($cut !== null) {
                $ways[] = ['line' => $run['line'], ...$cut];
            }
        }

        return $ways;
    }

    /**
     * The part of a run after the station: its later stops, and its track from the point nearest the station.
     *
     * @param  array{stops: list<array{name: string, lat: float, lng: float}>, points: list<array{0: float, 1: float}>}  $path
     * @return array{stops: list<array{name: string, lat: float, lng: float}>, points: list<array{0: float, 1: float}>}|null
     */
    private function fromStation(array $path, GeoPoint $station): ?array
    {
        $km = fn (float $lat, float $lng) => $this->distance->haversineKm($station->lat, $station->lng, $lat, $lng);

        $here = null;
        foreach ($path['stops'] as $i => $stop) {
            if ($km($stop['lat'], $stop['lng']) <= self::AT_STATION_KM && ($here === null || $km($stop['lat'], $stop['lng']) < $km($path['stops'][$here]['lat'], $path['stops'][$here]['lng']))) {
                $here = $i;
            }
        }
        // A run that ends here goes nowhere from here.
        if ($here === null || $here >= count($path['stops']) - 1) {
            return null;
        }

        $nearest = 0;
        foreach ($path['points'] as $i => [$lat, $lng]) {
            if ($km($lat, $lng) < $km($path['points'][$nearest][0], $path['points'][$nearest][1])) {
                $nearest = $i;
            }
        }

        return [
            'stops' => array_slice($path['stops'], $here + 1),
            'points' => array_slice($path['points'], $nearest),
        ];
    }
}
