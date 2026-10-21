<?php

namespace App\Transit\MockSample;

use App\Support\MockClock;
use App\Support\MockScenario;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Journey;
use App\Transit\Dto\JourneyResult;
use App\Transit\Dto\Leg;
use App\Transit\Dto\Place;
use Carbon\CarbonImmutable;

/**
 * The mock's sample ways while the local mock clock is on, so
 * tools/mock-compare sees the mock's trips. Routing has nothing for a past
 * day, so without these they couldn't be compared:
 * - the Blue Card appointment's trip (prototype/dev/design/connected/sample-day.js):
 *   Tram 4 from Neumarkt at 13:14 or 13:24 to Wiener Platz, scheduled times;
 *   the 13:14 cancelled, on live times, in the mock's "Your tram is cancelled"
 *   (MockScenario);
 * - the evening events' ways from Mülheim and Neumarkt (SampleEveningWays):
 *   there by the start, leaving now, and back after the event, with the
 *   direct walk and bike, each leg with its captured shape.
 * Everything else, and every request without the mock clock, goes to the
 * real router. Bound only in local (TravelServiceProvider).
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
        $at = MockClock::at()?->setTimezone('Europe/Berlin');
        if ($at === null) {
            return $this->routes->plan($from, $to, $departAt, $max, $arriveBy, $variety);
        }
        $day = $at->format('Y-m-d');

        $evening = self::evening($day, $from, $to, $departAt ?? $at, $arriveBy && $departAt !== null);
        if ($evening !== null) {
            return new JourneyResult($evening, 'transitous');
        }
        if (self::near($to, ...self::TO)) {
            return new JourneyResult(self::ways($day, $from, $to), 'transitous');
        }

        return $this->routes->plan($from, $to, $departAt, $max, $arriveBy, $variety);
    }

    /**
     * An evening event's captured ways, as a router would answer: there by
     * the time asked (arrive-by), else leaving from it; from the event, the
     * ways back. Null when the trip isn't one of the sample's.
     *
     * @return list<Journey>|null
     */
    private static function evening(string $day, GeoPoint $from, GeoPoint $to, CarbonImmutable $at, bool $arriveBy): ?array
    {
        foreach (SampleEveningWays::TRIPS as $trip) {
            if ($trip['date'] !== $day) {
                continue;
            }
            [, $startLat, $startLng] = $trip['from'];
            [, $venueLat, $venueLng] = $trip['to'];
            if (self::near($from, $startLat, $startLng) && self::near($to, $venueLat, $venueLng)) {
                $ways = $arriveBy ? $trip['arrive'] : [...$trip['now'], ...$trip['arrive']];
                $direct = ['walk' => $trip['walk'], 'bike' => $trip['bike']];
            } elseif (self::near($from, $venueLat, $venueLng) && self::near($to, $startLat, $startLng)) {
                $ways = $trip['back'];
                $direct = ['walk' => $trip['back_walk'], 'bike' => $trip['back_bike']];
            } else {
                continue;
            }

            $journeys = array_values(array_filter(
                array_map(fn (array $way) => self::captured($day, $from, $to, ...$way), $ways),
                fn (Journey $journey) => $arriveBy
                    ? ! $journey->arriveAt->greaterThan($at)
                    : ! $journey->departAt->lessThan($at->subMinute()),
            ));
            usort($journeys, fn (Journey $a, Journey $b) => $a->departAt <=> $b->departAt);

            // The direct ways, as Transitous times them: there by the time
            // asked (arrive-by), else leaving at it.
            foreach ($direct as $mode => $way) {
                if ($way !== null) {
                    [$minutes, $shape] = $way;
                    $leaves = $arriveBy ? $at->subMinutes($minutes) : $at;
                    $journeys[] = new Journey(
                        [new Leg($mode, new Place('', $from), new Place('', $to), $leaves, $leaves->addMinutes($minutes), $minutes, polyline: $shape)],
                        $leaves, $leaves->addMinutes($minutes), $minutes, 0,
                    );
                }
            }

            return $journeys;
        }

        return null;
    }

    private static function near(GeoPoint $point, float $lat, float $lng): bool
    {
        return abs($point->lat - $lat) <= 0.003 && abs($point->lng - $lng) <= 0.005;
    }

    /**
     * One captured way: its legs as the router gives them, scheduled times.
     *
     * @param  list<array{0: string, 1: ?string, 2: ?string, 3: string, 4: string, 5: string, 6: string, 7: ?int, 8: ?array{0: float, 1: float}, 9: ?array{0: float, 1: float}, 10: string}>  $legs
     */
    private static function captured(string $day, GeoPoint $from, GeoPoint $to, string $leave, string $there, array $legs): Journey
    {
        $at = fn (string $time) => CarbonImmutable::parse("{$day} {$time}", 'Europe/Berlin');
        $last = count($legs) - 1;
        $built = [];
        foreach ($legs as $i => [$mode, $line, $towards, $fromName, $toName, $leaves, $arrives, $stops, $fromPoint, $toPoint, $shape]) {
            $start = $i === 0 ? new Place('', $from) : new Place($fromName, new GeoPoint(...($fromPoint ?? [$from->lat, $from->lng])), kind: 'stop');
            $end = $i === $last ? new Place('', $to) : new Place($toName, new GeoPoint(...($toPoint ?? [$to->lat, $to->lng])), kind: 'stop');
            $ride = $mode !== 'walk';
            $built[] = new Leg($mode, $start, $end, $at($leaves), $at($arrives), (int) $at($leaves)->diffInMinutes($at($arrives)),
                $line, $towards, polyline: $shape !== '' ? $shape : null, stopsCount: $stops,
                scheduledDepartAt: $ride ? $at($leaves) : null, scheduledArriveAt: $ride ? $at($arrives) : null);
        }
        $rides = count(array_filter($legs, fn (array $leg) => $leg[0] !== 'walk'));

        return new Journey($built, $at($leave), $at($there), (int) $at($leave)->diffInMinutes($at($there)), max(0, $rides - 1));
    }

    /**
     * The sample's ways on a day (also what MockSample\TravelSeeder keeps as
     * the planned 13:24).
     *
     * @return list<Journey>
     */
    public static function ways(string $day, ?GeoPoint $from = null, ?GeoPoint $to = null): array
    {
        $from ??= new GeoPoint(50.9361, 6.9473);
        $to ??= new GeoPoint(...self::TO);

        return array_map(fn (array $way) => self::way($day, $from, $to, ...$way), self::WAYS);
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

    private static function way(string $day, GeoPoint $from, GeoPoint $to, string $leave, string $board, string $alight, string $there): Journey
    {
        $at = fn (string $time) => CarbonImmutable::parse("{$day} {$time}", 'Europe/Berlin');
        $start = new Place('', $from);
        $neumarkt = new Place('Köln Neumarkt', new GeoPoint(...self::NEUMARKT), kind: 'stop');
        $wiener = new Place('Köln Mülheim Wiener Platz', new GeoPoint(...self::WIENER_PLATZ), kind: 'stop');
        $end = new Place('', $to);
        $minutes = fn (string $a, string $b) => (int) $at($a)->diffInMinutes($at($b));
        // The mock's "Your tram is cancelled": the 13:14 is, which only live
        // times can say, so that situation's times read as live.
        $live = MockScenario::is('cancelled');

        return new Journey(
            legs: [
                new Leg('walk', $start, $neumarkt, $at($leave), $at($board), $minutes($leave, $board)),
                new Leg('tram', $neumarkt, $wiener, $at($board), $at($alight), $minutes($board, $alight), '4', 'Schlebusch',
                    lineColor: '#A01E5A', scheduledDepartAt: $at($board), scheduledArriveAt: $at($alight),
                    delayMin: $live ? 0 : null, realtime: $live, cancelled: $live && $board === '13:14'),
                new Leg('walk', $wiener, $end, $at($alight), $at($there), $minutes($alight, $there)),
            ],
            departAt: $at($leave),
            arriveAt: $at($there),
            durationMin: $minutes($leave, $there),
            transfers: 0,
        );
    }
}
