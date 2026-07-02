<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UserLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "I'm here →" — the explicit anchor confirmation that compensates for
 * imprecise PWA geolocation. Valid two hours, beats inferred sources.
 */
class LocationConfirmController extends Controller
{
    public function __invoke(Request $request, UserLocationService $locations): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'name' => ['nullable', 'string', 'max:200'],
        ]);

        $name = $locations->confirm(
            $request->user(),
            (float) $validated['lat'],
            (float) $validated['lng'],
            $validated['name'] ?? null,
        );

        return response()->json(['confirmed' => true, 'name' => $name]);
    }
}
