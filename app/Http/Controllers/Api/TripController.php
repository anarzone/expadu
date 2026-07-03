<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActiveTrip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The user's live trip session. Starting persists the chosen journey so it
 * survives an app close and shows across every screen (the app-wide banner)
 * until ended; ending clears it. One active trip per user.
 */
class TripController extends Controller
{
    /** Begin (or replace) the active trip with the chosen journey. */
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'journey' => ['required', 'array'],
            'journey.legs' => ['required', 'array', 'min:1'],
            'destination' => ['required', 'array'],
            'destination.name' => ['required', 'string', 'max:200'],
            'destination.lat' => ['required', 'numeric', 'between:-90,90'],
            'destination.lng' => ['required', 'numeric', 'between:-180,180'],
            'destination.emoji' => ['nullable', 'string', 'max:16'],
            'origin' => ['nullable', 'array'],
            'origin.name' => ['nullable', 'string', 'max:200'],
            'origin.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'origin.lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        // Persist the WHOLE journey — validating journey.legs makes Laravel
        // return only that sub-key, but the live timeline needs every field
        // (times, colours, intermediate-stop coordinates for GPS matching).
        $trip = ActiveTrip::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'journey' => $request->input('journey'),
                'destination' => $validated['destination'],
                'origin' => $validated['origin'] ?? null,
                'started_at' => now(),
            ],
        );

        return response()->json(['trip' => $trip->toShared()]);
    }

    /** End the active trip (arrived, or abandoned). */
    public function end(Request $request): JsonResponse
    {
        ActiveTrip::query()->where('user_id', $request->user()->id)->delete();

        return response()->json(['ended' => true]);
    }
}
