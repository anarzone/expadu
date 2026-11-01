<?php

namespace App\Transit;

use App\Composer\TravelEstimator;
use App\Services\KvbApiService;
use App\Services\WeatherService;
use Carbon\CarbonImmutable;

/**
 * What Plan a trip says it checked besides the departures and notices: the
 * weather while you're on the way (the hourly forecast) and lifts reported
 * out of order at the stations you board, change or get off at (KVB's lift
 * feed, as last fetched; never fetched on the request). Each says when it
 * isn't known rather than guessing. The next trip's card reads the same
 * lifts for its way (liftsOut()).
 */
final class TripConditions
{
    /** A lift this close to a stop the way uses is that stop's lift. */
    public const LIFT_RADIUS_M = 150;

    /** @var array<string, int|null> KVB station numbers by stop name, for this request */
    private array $stations = [];

    public function __construct(
        private readonly WeatherService $weather,
        private readonly KvbApiService $kvb,
        private readonly TravelEstimator $distance,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $journeys  Journey::toArray() rows
     * @return array{weather: array<string, mixed>, lifts: array{known: bool, by_journey: list<list<array{label: string, stop: string, since: ?string}>|null>}}
     */
    public function for(array $journeys): array
    {
        return [
            'weather' => $this->weather($journeys),
            'lifts' => $this->lifts($journeys),
        ];
    }

    /**
     * The forecast hours the options span, from the hour of the first
     * departure to the last arrival: the city's forecast, the one Today
     * shows (it doesn't change across a trip in town).
     *
     * @param  list<array<string, mixed>>  $journeys
     * @return array{available: bool, hours: list<array<string, mixed>>}
     */
    private function weather(array $journeys): array
    {
        $tz = 'Europe/Berlin';
        $instants = fn (string $key) => array_map(
            fn (string $at) => CarbonImmutable::parse($at)->getTimestamp(),
            array_filter(array_map(fn (array $j) => $j[$key] ?? null, $journeys), 'is_string'),
        );
        $departs = $instants('depart_at');
        $arrives = $instants('arrive_at');
        if ($departs === [] || $arrives === []) {
            return ['available' => false, 'hours' => []];
        }

        $from = CarbonImmutable::createFromTimestamp(min($departs), $tz)->startOfHour();
        $until = CarbonImmutable::createFromTimestamp(max($arrives), $tz);
        $hours = [];
        foreach (array_unique([$from->toDateString(), $until->toDateString()]) as $date) {
            $day = $this->weather->getDayForecast($date);
            foreach ($day['hours'] ?? [] as $hour) {
                if (! is_string($hour['time'] ?? null)) {
                    continue;
                }
                $at = CarbonImmutable::parse($hour['time'])->setTimezone($tz);
                if ($at->greaterThanOrEqualTo($from) && $at->lessThanOrEqualTo($until)) {
                    $hours[] = [
                        'at' => $at->toIso8601String(),
                        'condition' => $hour['condition'] ?? null,
                        'temperature' => $hour['temperature'] ?? null,
                        'precip' => $hour['precip'] ?? null,
                        'precip_probability' => $hour['precip_probability'] ?? null,
                    ];
                }
            }
        }

        return ['available' => $hours !== [], 'hours' => $hours];
    }

    /**
     * Per option, the lifts reported out at its stations; null for every
     * option while the feed isn't known.
     *
     * @param  list<array<string, mixed>>  $journeys
     * @return array{known: bool, by_journey: list<list<array{label: string, stop: string, since: ?string}>|null>}
     */
    private function lifts(array $journeys): array
    {
        $known = $this->kvb->knownElevatorDisruptions();
        if ($known === null) {
            return ['known' => false, 'by_journey' => array_fill(0, count($journeys), null)];
        }

        return ['known' => true, 'by_journey' => array_map(fn (array $journey) => $this->out($journey, $known), $journeys)];
    }

    /**
     * The lifts reported out at one way's stations; null while KVB's feed
     * isn't known.
     *
     * @param  array<string, mixed>  $journey  Journey::toArray()
     * @return list<array{label: string, stop: string, since: ?string}>|null
     */
    public function liftsOut(array $journey): ?array
    {
        $known = $this->kvb->knownElevatorDisruptions();

        return $known === null ? null : $this->out($journey, $known);
    }

    /**
     * A lift is a stop's when it's close by or KVB files it under that
     * station (its "Haltestellenbereich"): a big station's lifts can sit
     * further from the stop's point than the radius.
     *
     * @param  array<string, mixed>  $journey
     * @param  list<array<string, mixed>>  $known
     * @return list<array{label: string, stop: string, since: ?string}>
     */
    private function out(array $journey, array $known): array
    {
        if ($known === []) {
            return [];
        }
        $out = [];
        foreach ($this->stations($journey) as $stop) {
            $station = $this->stationNumber($stop['name']);
            foreach ($known as $lift) {
                $metres = 1000 * $this->distance->haversineKm($stop['lat'], $stop['lng'], (float) $lift['lat'], (float) $lift['lng']);
                $here = $metres <= self::LIFT_RADIUS_M
                    || ($station !== null && is_numeric($lift['stop_area'] ?? null) && (int) $lift['stop_area'] === $station);
                if ($here && ! isset($out[$lift['id']])) {
                    $out[$lift['id']] = ['label' => (string) $lift['label'], 'stop' => $stop['name'], 'since' => $lift['timestamp'] ?: null];
                }
            }
        }

        return array_values($out);
    }

    /** The KVB station a stop is, by its name as the stop signs say it ("Köln Neumarkt" is Neumarkt). */
    private function stationNumber(string $name): ?int
    {
        $city = (string) config('geocoding.search_default.city');
        $sign = $city !== '' && str_starts_with($name, "{$city} ") ? substr($name, strlen($city) + 1) : $name;
        if (! array_key_exists($sign, $this->stations)) {
            $points = $sign === '' ? [] : $this->kvb->stationPoints($sign);
            $this->stations[$sign] = $points === [] ? null : KvbApiService::stationNumber($points[0]['id']);
        }

        return $this->stations[$sign];
    }

    /**
     * Where the way boards, changes and gets off.
     *
     * @param  array<string, mixed>  $journey
     * @return list<array{name: string, lat: float, lng: float}>
     */
    private function stations(array $journey): array
    {
        $stops = [];
        foreach ($journey['legs'] ?? [] as $leg) {
            if (in_array($leg['mode'] ?? 'walk', ['walk', 'bike'], true)) {
                continue;
            }
            foreach (['from', 'to'] as $end) {
                $place = $leg[$end] ?? null;
                if (is_numeric($place['lat'] ?? null) && is_numeric($place['lng'] ?? null)) {
                    $stops[] = ['name' => (string) ($place['name'] ?? ''), 'lat' => (float) $place['lat'], 'lng' => (float) $place['lng']];
                }
            }
        }

        return $stops;
    }
}
