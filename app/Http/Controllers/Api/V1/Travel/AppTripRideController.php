<?php

namespace App\Http\Controllers\Api\V1\Travel;

use App\Http\Controllers\Controller;
use App\Models\ActiveTrip;
use App\Transit\TripSync;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The tram a rider is really on, on the trip they're following (the iPhone app's trip sync): which run
 * the phone left a ride's stop on, switching the trip to a run, and Undo. Only ever their own running
 * trip; without one, there's nothing here.
 */
class AppTripRideController extends Controller
{
    public function check(Request $request, TripSync $sync): JsonResponse
    {
        $valid = $request->validate([
            'leg' => ['required', 'integer', 'min:0', 'max:40'],
            'from' => ['nullable', 'string', 'max:200'],
            'at' => ['nullable', 'date'],
            'left_at' => ['required', 'date'],
        ]);
        $trip = $this->trip($request);
        $leg = $this->leg($sync, $trip, $valid);
        $leftAt = CarbonImmutable::parse($valid['left_at']);
        // When the phone left a stop is now-ish; anything else is no ride being followed.
        if ($leftAt->lt(now()->subHour()) || $leftAt->gt(now()->addMinutes(5))) {
            return response()->json(['message' => 'That time is not this trip’s.'], 422);
        }

        return response()->json($sync->check($trip, $leg, $leftAt));
    }

    public function switch(Request $request, TripSync $sync): JsonResponse
    {
        $valid = $request->validate([
            'leg' => ['required', 'integer', 'min:0', 'max:40'],
            'from' => ['nullable', 'string', 'max:200'],
            'at' => ['nullable', 'date'],
            'line' => ['required', 'string', 'max:20'],
            'depart_at' => ['required', 'date'],
            'trip' => ['nullable', 'string', 'max:300'],
        ]);
        $trip = $this->trip($request);
        $departAt = CarbonImmutable::parse($valid['depart_at']);
        if ($departAt->lt(now()->subHours(2)) || $departAt->gt(now()->addHours(3))) {
            return response()->json(['message' => 'That tram is not this trip’s.'], 422);
        }

        $switched = $sync->switchTo($trip, $this->leg($sync, $trip, $valid), $valid['line'], $departAt, $valid['trip'] ?? null);
        if ($switched === null) {
            return response()->json(['message' => 'That tram can’t be followed from here. Try a new route.'], 422);
        }

        return response()->json([...$switched, 'trip' => $trip->fresh()?->toShared()]);
    }

    /** A change a delay broke: the next tram they can catch there, switched to (with Undo). */
    public function next(Request $request, TripSync $sync): JsonResponse
    {
        $valid = $request->validate([
            'leg' => ['required', 'integer', 'min:0', 'max:40'],
            'from' => ['nullable', 'string', 'max:200'],
            'at' => ['nullable', 'date'],
            'ready_at' => ['required', 'date'],
        ]);
        $trip = $this->trip($request);
        $readyAt = CarbonImmutable::parse($valid['ready_at']);
        if ($readyAt->lt(now()->subMinutes(30)) || $readyAt->gt(now()->addHours(3))) {
            return response()->json(['message' => 'That time is not this trip’s.'], 422);
        }

        $switched = $sync->catchNext($trip, $this->leg($sync, $trip, $valid), $readyAt);
        if ($switched === null) {
            return response()->json(['message' => 'No tram there in time. Try a new route.'], 422);
        }

        return response()->json([...$switched, 'trip' => $trip->fresh()?->toShared()]);
    }

    /**
     * A change as a live board: every tram on the ride's stretch from a little before it was planned,
     * live as the feed knows them, the trip's own marked; and whether Expadu switches trams for them
     * (the auto_switch_trams setting).
     */
    public function board(Request $request, TripSync $sync): JsonResponse
    {
        $valid = $request->validate([
            'leg' => ['required', 'integer', 'min:0', 'max:40'],
            'from' => ['nullable', 'string', 'max:200'],
            'at' => ['nullable', 'date'],
            // When they can be at the stop, when the phone knows (walking to their first tram).
            'since' => ['nullable', 'date'],
        ]);
        $trip = $this->trip($request);

        return response()->json([
            /** @var list<array{line: string, headsign: string|null, color: string|null, depart_at: string, depart_time: string, arrive_at: string, arrive_time: string, trip: string|null, delay_min: int|null, cancelled: bool, yours: bool}> */
            'runs' => $sync->board($trip, $this->leg($sync, $trip, $valid), CarbonImmutable::now(),
                isset($valid['since']) ? CarbonImmutable::parse($valid['since']) : null),
            /** @var bool Switch trams for me: Expadu moves them to the best tram they can still make (with Undo); off, it only suggests. */
            'auto_switch' => $request->user()->setting('auto_switch_trams') !== false,
        ]);
    }

    public function undo(Request $request, TripSync $sync): JsonResponse
    {
        $valid = $request->validate(['undo' => ['required', 'uuid']]);
        $trip = $this->trip($request);
        if (! $sync->undo($trip, $valid['undo'])) {
            return response()->json(['message' => 'That can’t be undone any more.'], 422);
        }

        return response()->json(['trip' => $trip->fresh()?->toShared()]);
    }

    /** @param  array<string, mixed>  $valid */
    private function leg(TripSync $sync, ActiveTrip $trip, array $valid): int
    {
        $leg = $sync->rideIndex($trip, (int) $valid['leg'], $valid['from'] ?? null, isset($valid['at']) ? CarbonImmutable::parse($valid['at']) : null);
        abort_if($leg === null, 422, 'That ride isn’t on this trip.');

        return $leg;
    }

    private function trip(Request $request): ActiveTrip
    {
        $trip = $request->user()->activeTrip()->first();
        abort_if($trip === null || $trip->isOver(), 404, 'No trip is running.');

        return $trip;
    }
}
