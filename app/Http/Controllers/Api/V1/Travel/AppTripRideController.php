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
            'left_at' => ['required', 'date'],
        ]);
        $trip = $this->trip($request);
        $leftAt = CarbonImmutable::parse($valid['left_at']);
        // When the phone left a stop is now-ish; anything else is no ride being followed.
        if ($leftAt->lt(now()->subHour()) || $leftAt->gt(now()->addMinutes(5))) {
            return response()->json(['message' => 'That time is not this trip’s.'], 422);
        }

        return response()->json($sync->check($trip, (int) $valid['leg'], $leftAt));
    }

    public function switch(Request $request, TripSync $sync): JsonResponse
    {
        $valid = $request->validate([
            'leg' => ['required', 'integer', 'min:0', 'max:40'],
            'line' => ['required', 'string', 'max:20'],
            'depart_at' => ['required', 'date'],
            'trip' => ['nullable', 'string', 'max:300'],
        ]);
        $trip = $this->trip($request);
        $departAt = CarbonImmutable::parse($valid['depart_at']);
        if ($departAt->lt(now()->subHours(2)) || $departAt->gt(now()->addHours(3))) {
            return response()->json(['message' => 'That tram is not this trip’s.'], 422);
        }

        $switched = $sync->switchTo($trip, (int) $valid['leg'], $valid['line'], $departAt, $valid['trip'] ?? null);
        if ($switched === null) {
            return response()->json(['message' => 'That tram can’t be followed from here. Try a new route.'], 422);
        }

        return response()->json([...$switched, 'trip' => $trip->fresh()?->toShared()]);
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

    private function trip(Request $request): ActiveTrip
    {
        $trip = $request->user()->activeTrip()->first();
        abort_if($trip === null || $trip->isOver(), 404, 'No trip is running.');

        return $trip;
    }
}
