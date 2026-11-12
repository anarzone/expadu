<?php

namespace App\Transit;

use App\Services\KvbLineColors;
use App\Transit\Dto\GeoPoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The trams a route's first ride could be: every run straight from its stop to where it changes
 * that still makes the same connection. The router keeps only the last such run (the 18 at 19:44
 * reaches the same 12 as the 16 at 19:37, so the 16 is dropped), while someone at the stop with the
 * 16 coming wants to know they can take it, as Apple Maps shows "16 / 18". Each route gets
 * `first_ride_also`: the earlier runs, soonest first. Best effort: nothing is added when the
 * engine doesn't answer.
 */
class FirstRideChoices
{
    /** Stretches read for one plan at most (the distinct first rides of the routes shown). */
    private const MAX_STRETCHES = 3;

    /** Earlier runs named on one route at most. */
    private const MAX_ALSO = 3;

    public function __construct(
        private readonly MotisAdapter $motis,
        private readonly TransitousAdapter $transitous,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $journeys  as the planner sends them (Journey::toArray)
     * @return list<array<string, mixed>>
     */
    public function add(array $journeys, float $budgetSeconds = 4.0): array
    {
        $engine = $this->engine();
        if ($engine === null || $budgetSeconds < 1.0) {
            return $journeys;
        }
        $engine = $engine->withTimeBudget($budgetSeconds);

        $read = [];
        foreach ($journeys as $i => $journey) {
            $first = $this->firstRide($journey);
            if ($first === null) {
                continue;
            }
            [, $ride] = $first;
            $key = implode('|', [$ride['from']['name'] ?? '', $ride['to']['name'] ?? '', substr((string) $ride['depart_at'], 0, 16)]);
            if (! array_key_exists($key, $read)) {
                if (count($read) >= self::MAX_STRETCHES) {
                    continue;
                }
                try {
                    $read[$key] = $engine->directRides(
                        new GeoPoint((float) $ride['from']['lat'], (float) $ride['from']['lng']),
                        new GeoPoint((float) $ride['to']['lat'], (float) $ride['to']['lng']),
                        CarbonImmutable::parse($ride['depart_at'])->subMinutes(15)->max(CarbonImmutable::now()),
                    );
                } catch (\Throwable $e) {
                    Log::info('first ride choices failed', ['error' => $e->getMessage()]);
                    $read[$key] = [];
                }
            }

            // An earlier run that gets there no later than the route's own surely makes the same connection.
            $leaves = CarbonImmutable::parse($ride['depart_at']);
            $there = CarbonImmutable::parse($ride['arrive_at']);
            $also = collect($read[$key])
                // Other lines only: an earlier run of the same line is a route (and a row) of its own.
                ->filter(fn (array $run) => ! $run['cancelled'] && $run['line'] !== (string) ($ride['line'] ?? '')
                    && CarbonImmutable::parse($run['depart_at'])->lt($leaves)
                    && CarbonImmutable::parse($run['depart_at'])->gte(CarbonImmutable::now()->subMinute())
                    && CarbonImmutable::parse($run['arrive_at'])->lte($there))
                ->sortBy('depart_at')
                ->take(self::MAX_ALSO)
                ->map(fn (array $run) => [
                    'line' => $run['line'],
                    'color' => KvbLineColors::for($run['line']),
                    'depart_at' => $run['depart_at'],
                    'depart_time' => CarbonImmutable::parse($run['depart_at'])->format('H:i'),
                ])
                ->values()
                ->all();
            if ($also !== []) {
                $journeys[$i]['first_ride_also'] = $also;
            }
        }

        return $journeys;
    }

    /**
     * The route's first ride and where it is in the legs; null on foot or by bike.
     *
     * @param  array<string, mixed>  $journey
     * @return array{0: int, 1: array<string, mixed>}|null
     */
    private function firstRide(array $journey): ?array
    {
        foreach ($journey['legs'] ?? [] as $index => $leg) {
            if (is_array($leg) && ! in_array($leg['mode'] ?? 'walk', ['walk', 'bike'], true)) {
                return isset($leg['from']['lat'], $leg['to']['lat'], $leg['depart_at'], $leg['arrive_at']) ? [$index, $leg] : null;
            }
        }

        return null;
    }

    private function engine(): ?TransitousAdapter
    {
        return match (true) {
            filled(config('services.motis.url')) => $this->motis,
            filled(config('services.transitous.url')) => $this->transitous,
            default => null,
        };
    }
}
