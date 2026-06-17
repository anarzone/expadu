<?php

namespace App\Transit;

use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Journey;
use App\Transit\Dto\JourneyResult;
use App\Transit\Dto\Leg;
use App\Transit\Dto\Place;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Primary provider: the community-run Transitous instance of the MOTIS
 * engine (api.transitous.org). One service covers routing, geocoding and
 * reverse geocoding for all of Germany via DELFI feeds, VRS included.
 * Respect their usage policy — we cache aggressively in the failover
 * wrapper, this adapter stays cache-free.
 */
class TransitousAdapter implements RouteService
{
    /** MOTIS modes → our normalized modes. */
    private const MODE_MAP = [
        'WALK' => 'walk',
        'BIKE' => 'bike',
        'BUS' => 'bus',
        'COACH' => 'bus',
        'TRAM' => 'tram',
        'SUBWAY' => 'subway',
        'METRO' => 'subway',
        'RAIL' => 'rail',
        'HIGHSPEED_RAIL' => 'rail',
        'LONG_DISTANCE' => 'rail',
        'REGIONAL_RAIL' => 'rail',
        'REGIONAL_FAST_RAIL' => 'rail',
        'NIGHT_RAIL' => 'rail',
        'FERRY' => 'ferry',
    ];

    public function plan(GeoPoint $from, GeoPoint $to, ?CarbonImmutable $departAt = null, int $max = 3): JourneyResult
    {
        $query = [
            'fromPlace' => "{$from->lat},{$from->lng}",
            'toPlace' => "{$to->lat},{$to->lng}",
            'numItineraries' => $max,
        ];

        if ($departAt !== null) {
            $query['time'] = $departAt->utc()->toIso8601ZuluString();
        }

        $response = Http::baseUrl($this->baseUrl())
            ->timeout(8)
            ->connectTimeout(3)
            ->withHeaders(['User-Agent' => 'expadu.com'])
            ->get($this->planPath(), $query)
            ->throw();

        $journeys = [];
        foreach ($response->json('itineraries', []) as $itinerary) {
            $journey = $this->mapItinerary($itinerary);
            if ($journey !== null) {
                $journeys[] = $journey;
            }
        }

        return new JourneyResult($journeys, $this->source());
    }

    public function geocode(string $query, ?GeoPoint $bias = null): array
    {
        $params = ['text' => $query, 'language' => 'de'];
        if ($bias !== null) {
            $params['place'] = "{$bias->lat},{$bias->lng}";
        }

        $response = Http::baseUrl($this->baseUrl())
            ->timeout(5)
            ->connectTimeout(3)
            ->withHeaders(['User-Agent' => 'expadu.com'])
            ->get('/api/v1/geocode', $params)
            ->throw();

        return collect($response->json())
            ->filter(fn ($hit) => isset($hit['lat'], $hit['lon'], $hit['name']))
            ->map(fn ($hit) => new Place(
                name: (string) $hit['name'],
                point: new GeoPoint((float) $hit['lat'], (float) $hit['lon']),
                stopId: ($hit['type'] ?? null) === 'STOP' ? ($hit['id'] ?? null) : null,
            ))
            ->values()
            ->all();
    }

    public function reverseGeocode(GeoPoint $point): ?Place
    {
        $response = Http::baseUrl($this->baseUrl())
            ->timeout(5)
            ->connectTimeout(3)
            ->withHeaders(['User-Agent' => 'expadu.com'])
            ->get('/api/v1/reverse-geocode', [
                'place' => "{$point->lat},{$point->lng}",
            ])
            ->throw();

        $hit = collect($response->json())->first(fn ($h) => isset($h['lat'], $h['lon'], $h['name']));

        return $hit === null ? null : new Place(
            name: (string) $hit['name'],
            point: new GeoPoint((float) $hit['lat'], (float) $hit['lon']),
        );
    }

    /**
     * @param  array<string, mixed>  $itinerary
     */
    private function mapItinerary(array $itinerary): ?Journey
    {
        $legs = [];

        foreach ($itinerary['legs'] ?? [] as $rawLeg) {
            $from = $rawLeg['from'] ?? null;
            $to = $rawLeg['to'] ?? null;
            if (! $from || ! $to) {
                return null;
            }

            $mode = self::MODE_MAP[$rawLeg['mode'] ?? 'WALK'] ?? 'walk';

            $legs[] = new Leg(
                mode: $mode,
                from: $this->mapPlace($from),
                to: $this->mapPlace($to),
                departAt: CarbonImmutable::parse($rawLeg['startTime'])->setTimezone('Europe/Berlin'),
                arriveAt: CarbonImmutable::parse($rawLeg['endTime'])->setTimezone('Europe/Berlin'),
                durationMin: (int) ceil(($rawLeg['duration'] ?? 0) / 60),
                lineName: $rawLeg['routeShortName'] ?? null,
                headsign: $rawLeg['headsign'] ?? null,
                polyline: $rawLeg['legGeometry']['points'] ?? null,
                // Stops ridden = intermediate stops passed + the get-off stop.
                stopsCount: $mode !== 'walk' && isset($rawLeg['intermediateStops'])
                    ? count($rawLeg['intermediateStops']) + 1
                    : null,
            );
        }

        if ($legs === []) {
            return null;
        }

        return new Journey(
            legs: $legs,
            departAt: CarbonImmutable::parse($itinerary['startTime'])->setTimezone('Europe/Berlin'),
            arriveAt: CarbonImmutable::parse($itinerary['endTime'])->setTimezone('Europe/Berlin'),
            durationMin: (int) ceil(($itinerary['duration'] ?? 0) / 60),
            transfers: (int) ($itinerary['transfers'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>  $place
     */
    private function mapPlace(array $place): Place
    {
        $name = (string) ($place['name'] ?? '');

        return new Place(
            name: in_array($name, ['START', 'END'], true) ? '' : $name,
            point: new GeoPoint((float) ($place['lat'] ?? 0), (float) ($place['lon'] ?? 0)),
            stopId: $place['stopId'] ?? null,
        );
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('services.transitous.url', 'https://api.transitous.org'), '/');
    }

    /**
     * The plan endpoint path. Transitous runs a build that serves v3; our
     * self-hosted MOTIS (see {@see MotisAdapter}) serves v1. Geocode and
     * reverse-geocode are v1 on both.
     */
    protected function planPath(): string
    {
        return '/api/v3/plan';
    }

    /** The `source` stamped on the JourneyResult (drives the frontend path). */
    protected function source(): string
    {
        return 'transitous';
    }
}
