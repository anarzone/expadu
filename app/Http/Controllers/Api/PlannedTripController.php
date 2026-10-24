<?php

namespace App\Http\Controllers\Api;

use App\Day\PlannedTrips;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlanTripRequest;
use App\Models\PlannedTrip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Plan a trip's "Add to plan": the way picked for a plan on your day, its
 * "Remind me when to leave", and taking it off again. Only ever your own;
 * kept through PlannedTrips, as Composer's plan_trip_for_later keeps it.
 */
class PlannedTripController extends Controller
{
    public function store(PlanTripRequest $request, PlannedTrips $planned): JsonResponse
    {
        $trip = $planned->keep($request->user(), $request->input('date'), $request->plan(), $request->input('journey'), $request->boolean('remind'));

        return response()->json(['planned' => $trip->toShared()], 201);
    }

    public function update(Request $request, int $plannedTrip): JsonResponse
    {
        $request->validate(['remind' => ['required', 'boolean']]);
        $trip = $this->own($request, $plannedTrip);
        $trip->update(['remind' => $request->boolean('remind'), 'reminded_at' => null]);

        return response()->json(['planned' => $trip->toShared()]);
    }

    public function destroy(Request $request, int $plannedTrip): Response
    {
        $this->own($request, $plannedTrip)->delete();

        return response()->noContent();
    }

    /** Someone else's planned way is as good as not there. */
    private function own(Request $request, int $id): PlannedTrip
    {
        return PlannedTrip::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }
}
