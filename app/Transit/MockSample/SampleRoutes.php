<?php

namespace App\Transit\MockSample;

use App\Support\MockClock;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Journey;
use App\Transit\Dto\JourneyResult;
use App\Transit\Dto\Leg;
use App\Transit\Dto\Place;
use Carbon\CarbonImmutable;

/**
 * The mock's sample ways (prototype/dev/design/connected/sample-day.js: the
 * Blue Card appointment's trip) while the local mock clock is on, so
 * tools/mock-compare sees the mock's Leave now: Tram 4 from Neumarkt at
 * 13:14 or 13:24 to Wiener Platz, scheduled times. Routing has nothing for
 * a past day, so without this the trip couldn't be compared. Everything
 * else, and every request without the mock clock, goes to the real router.
 * Bound only in local (TravelServiceProvider).
 */
final class SampleRoutes implements RouteService
{
    /** The sample's way ends here (the appointment at Wiener Platz). */
    private const TO = [50.96103, 7.0048];

    private const NEUMARKT = [50.9363, 6.9469];

    private const WIENER_PLATZ = [50.9610, 7.0040];

    /** Each way: [walk to the stop, tram leaves, tram arrives, there]. */
    private const WAYS = [
        ['13:10', '13:14', '13:42', '13:48'],
        ['13:20', '13:24', '13:52', '13:58'],
    ];

    public function __construct(private RouteService $routes) {}

    public function withTimeBudget(float $seconds): static
    {
        return new self($this->routes->withTimeBudget($seconds));
    }

    public function plan(GeoPoint $from, GeoPoint $to, ?CarbonImmutable $departAt = null, int $max = 6, bool $arriveBy = false, bool $variety = false): JourneyResult
    {
        if (! MockClock::active() || abs($to->lat - self::TO[0]) > 0.003 || abs($to->lng - self::TO[1]) > 0.005) {
            return $this->routes->plan($from, $to, $departAt, $max, $arriveBy, $variety);
        }

        $day = (MockClock::at() ?? CarbonImmutable::now())->setTimezone('Europe/Berlin')->format('Y-m-d');
        $journeys = array_map(fn (array $way) => $this->way($day, $from, $to, ...$way), self::WAYS);

        return new JourneyResult($journeys, 'transitous');
    }

    public function geocode(string $query, ?GeoPoint $bias = null): array
    {
        return $this->routes->geocode($query, $bias);
    }

    public function reverseGeocode(GeoPoint $point): ?Place
    {
        return $this->routes->reverseGeocode($point);
    }

    public function travelMatrix(GeoPoint $origin, array $destinations, string $mode = 'BIKE'): array
    {
        return $this->routes->travelMatrix($origin, $destinations, $mode);
    }

    private function way(string $day, GeoPoint $from, GeoPoint $to, string $leave, string $board, string $alight, string $there): Journey
    {
        $at = fn (string $time) => CarbonImmutable::parse("{$day} {$time}", 'Europe/Berlin');
        $start = new Place('', $from);
        $neumarkt = new Place('Köln Neumarkt', new GeoPoint(...self::NEUMARKT), kind: 'stop');
        $wiener = new Place('Köln Mülheim Wiener Platz', new GeoPoint(...self::WIENER_PLATZ), kind: 'stop');
        $end = new Place('', $to);
        $minutes = fn (string $a, string $b) => (int) $at($a)->diffInMinutes($at($b));

        return new Journey(
            legs: [
                new Leg('walk', $start, $neumarkt, $at($leave), $at($board), $minutes($leave, $board)),
                new Leg('tram', $neumarkt, $wiener, $at($board), $at($alight), $minutes($board, $alight), '4', 'Schlebusch',
                    lineColor: '#A01E5A', scheduledDepartAt: $at($board), scheduledArriveAt: $at($alight)),
                new Leg('walk', $wiener, $end, $at($alight), $at($there), $minutes($alight, $there)),
            ],
            departAt: $at($leave),
            arriveAt: $at($there),
            durationMin: $minutes($leave, $there),
            transfers: 0,
        );
    }
}
