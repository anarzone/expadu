<?php

namespace App\Http\Controllers\Api;

use App\Composer\TravelEstimator;
use App\Http\Controllers\Controller;
use App\Services\UserLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "I'm here →" — the explicit anchor confirmation that compensates for
 * imprecise PWA geolocation. Valid two hours, beats inferred sources.
 * `moved` says whether it's more than MOVED_METRES from where the page was
 * worked out from, so a page only reloads what depends on it when it is.
 */
class LocationConfirmController extends Controller
{
    private const MOVED_METRES = 100;

    public function __invoke(Request $request, UserLocationService $locations, TravelEstimator $distance): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'name' => ['nullable', 'string', 'max:200'],
        ]);

        $before = $locations->context($request->user());
        $moved = ! $before->hasOrigin()
            || 1000 * $distance->haversineKm((float) $before->lat, (float) $before->lng, (float) $validated['lat'], (float) $validated['lng']) > self::MOVED_METRES;

        $name = $locations->confirm(
            $request->user(),
            (float) $validated['lat'],
            (float) $validated['lng'],
            $validated['name'] ?? null,
        );

        return response()->json(['confirmed' => true, 'name' => $name, 'moved' => $moved]);
    }
}
