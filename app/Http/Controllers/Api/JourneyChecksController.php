<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Journey;
use App\Transit\TripDisruptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Plan a trip's second request: the live runs at the stops its ways board
 * (lines in trouble, cancelled runs), read from the boards after the ways
 * are on screen, so the ways never wait for them. The ways are kept here by
 * an id for ten minutes, for the account that planned them only.
 */
class JourneyChecksController extends Controller
{
    private const KEEP_SECONDS = 600;

    /**
     * @param  list<array<string, mixed>>  $journeys  the ways as the plan answered them
     */
    public static function keep(int $userId, array $journeys, GeoPoint $from, GeoPoint $to): string
    {
        $id = (string) Str::uuid();
        Cache::put(self::key($id), [
            'user' => $userId,
            'journeys' => $journeys,
            'from' => [$from->lat, $from->lng],
            'to' => [$to->lat, $to->lng],
        ], self::KEEP_SECONDS);

        return $id;
    }

    public function __invoke(Request $request, TripDisruptions $disruptions): JsonResponse
    {
        $validated = $request->validate(['checks' => ['required', 'uuid']]);
        $kept = Cache::get(self::key($validated['checks']));
        if (! is_array($kept) || $kept['user'] !== $request->user()->id) {
            return response()->json(['message' => 'These ways are no longer kept. Plan again.'], 404);
        }

        $trip = $disruptions->for(
            array_map(fn (array $journey) => Journey::fromArray($journey), $kept['journeys']),
            new GeoPoint(...$kept['from']),
            new GeoPoint(...$kept['to']),
        );

        return response()->json(['trouble' => $trip['trouble'], 'gone' => $trip['gone']]);
    }

    private static function key(string $id): string
    {
        return "journey_checks:{$id}";
    }
}
