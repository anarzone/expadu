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
 * isn't known rather than guessing.
 */
final class TripConditions
{
    /** A lift this close to a stop the way uses is that stop's lift. */
    public const LIFT_RADIUS_M = 150;

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

        $byJourney = [];
        foreach ($journeys as $journey) {
            $out = [];
            foreach ($this->stations($journey) as $stop) {
                foreach ($known as $lift) {
                    $metres = 1000 * $this->distance->haversineKm($stop['lat'], $stop['lng'], (float) $lift['lat'], (float) $lift['lng']);
                    if ($metres <= self::LIFT_RADIUS_M && ! isset($out[$lift['id']])) {
                        $out[$lift['id']] = ['label' => (string) $lift['label'], 'stop' => $stop['name'], 'since' => $lift['timestamp'] ?: null];
                    }
                }
            }
            $byJourney[] = array_values($out);
        }

        return ['known' => true, 'by_journey' => $byJourney];
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
