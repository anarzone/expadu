<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NearbyStopService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NearbyDeparturesController extends Controller
{
    public function __invoke(Request $request, NearbyStopService $nearbyService): JsonResponse
    {
        $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $user = $request->user();
        $lat = (float) $request->query('lat');
        $lng = (float) $request->query('lng');

        $dest = $nearbyService->predictDestination($user, $lat, $lng);

        return response()->json(
            $nearbyService->getDeparturesByType(
                $lat,
                $lng,
                $dest['lat'] ?? null,
                $dest['lng'] ?? null,
                $dest['name'] ?? null,
            )
        );
    }
}
