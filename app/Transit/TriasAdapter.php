<?php

namespace App\Transit;

use App\Services\VrsTriasService;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Journey;
use App\Transit\Dto\JourneyResult;
use App\Transit\Dto\Leg;
use App\Transit\Dto\Place;
use Carbon\CarbonImmutable;

/**
 * Fallback provider: thin mapper over the existing VRS TRIAS XML client.
 * Only journey planning is supported — TRIAS location search is
 * stop-based, so geocoding falls through to the degraded path.
 */
class TriasAdapter implements RouteService
{
    public function __construct(
        private readonly VrsTriasService $trias,
    ) {}

    public function plan(GeoPoint $from, GeoPoint $to, ?CarbonImmutable $departAt = null, int $max = 3): JourneyResult
    {
        $result = $this->trias->planJourney($from->lat, $from->lng, $to->lat, $to->lng, $max);

        if ($result === null || empty($result['trips'])) {
            throw new \RuntimeException('TRIAS returned no trips');
        }

        $journeys = [];
        foreach ($result['trips'] as $trip) {
            $journey = $this->mapTrip($trip, $from, $to);
            if ($journey !== null) {
                $journeys[] = $journey;
            }
        }

        if ($journeys === []) {
            throw new \RuntimeException('TRIAS trips could not be mapped');
        }

        return new JourneyResult($journeys, 'trias');
    }

    public function geocode(string $query, ?GeoPoint $bias = null): array
    {
        return [];
    }

    public function reverseGeocode(GeoPoint $point): ?Place
    {
        return null;
    }

    /**
     * TRIAS parse output uses H:i display times; anchor them to today.
     * A journey crossing midnight gets its arrival bumped a day forward.
     *
     * @param  array<string, mixed>  $trip
     */
    private function mapTrip(array $trip, GeoPoint $from, GeoPoint $to): ?Journey
    {
        $departAt = $this->todayAt($trip['departure_time'] ?? '');
        $arriveAt = $this->todayAt($trip['arrival_time'] ?? '');
        if ($departAt === null || $arriveAt === null) {
            return null;
        }
        if ($arriveAt->lessThan($departAt)) {
            $arriveAt = $arriveAt->addDay();
        }

        $legs = [];
        $cursor = $departAt;

        foreach ($trip['segments'] ?? [] as $segment) {
            $leg = $this->mapSegment($segment, $cursor, $from, $to);
            if ($leg !== null) {
                $legs[] = $leg;
                $cursor = $leg->arriveAt;
            }
        }

        if ($legs === []) {
            return null;
        }

        return new Journey(
            legs: $legs,
            departAt: $departAt,
            arriveAt: $arriveAt,
            durationMin: (int) ($trip['total_duration_min'] ?? max(1, $departAt->diffInMinutes($arriveAt))),
            transfers: (int) ($trip['transfers'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>  $segment
     */
    private function mapSegment(array $segment, CarbonImmutable $cursor, GeoPoint $from, GeoPoint $to): ?Leg
    {
        $type = $segment['type'] ?? '';
        $departAt = $this->todayAt($segment['departure_time'] ?? '') ?? $cursor;
        $durationMin = max(1, (int) ($segment['duration_min'] ?? 1));
        $arriveAt = $this->todayAt($segment['arrival_time'] ?? '') ?? $departAt->addMinutes($durationMin);
        if ($arriveAt->lessThan($departAt)) {
            $arriveAt = $arriveAt->addDay();
        }

        $coordinates = $segment['coordinates'] ?? [];
        $first = $coordinates[0] ?? null;
        $last = $coordinates !== [] ? end($coordinates) : null;

        if ($type === 'transit') {
            return new Leg(
                mode: match ($segment['mode'] ?? '') {
                    'rail' => 'rail',
                    'tram' => 'tram',
                    'metro', 'subway' => 'subway',
                    default => 'bus',
                },
                from: new Place(
                    name: (string) ($segment['boarding_stop'] ?? ''),
                    point: new GeoPoint((float) ($first[1] ?? $from->lat), (float) ($first[0] ?? $from->lng)),
                    stopId: $segment['boarding_stop_id'] ?? null,
                ),
                to: new Place(
                    name: (string) ($segment['alighting_stop'] ?? ''),
                    point: new GeoPoint((float) ($last[1] ?? $to->lat), (float) ($last[0] ?? $to->lng)),
                    stopId: $segment['alighting_stop_id'] ?? null,
                ),
                departAt: $departAt,
                arriveAt: $arriveAt,
                durationMin: $durationMin,
                lineName: $segment['line'] ?? null,
                headsign: $segment['direction'] ?? null,
            );
        }

        // walk / interchange segments
        return new Leg(
            mode: 'walk',
            from: new Place(name: '', point: new GeoPoint((float) ($first[1] ?? $from->lat), (float) ($first[0] ?? $from->lng))),
            to: new Place(name: '', point: new GeoPoint((float) ($last[1] ?? $to->lat), (float) ($last[0] ?? $to->lng))),
            departAt: $departAt,
            arriveAt: $arriveAt,
            durationMin: $durationMin,
        );
    }

    private function todayAt(string $time): ?CarbonImmutable
    {
        if (! preg_match('/^\d{2}:\d{2}$/', $time)) {
            return null;
        }

        return CarbonImmutable::now('Europe/Berlin')
            ->setTimeFromTimeString($time.':00');
    }
}
