<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Transit\StationLinesMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Nearby's Map: the lines through the board's station and their next stops
 * (StationLinesMap). The station's own name and position, never yours.
 */
class StationLinesMapController extends Controller
{
    public function __invoke(Request $request, StationLinesMap $map): JsonResponse
    {
        $station = $request->validate([
            'stop' => ['required', 'string', 'min:2', 'max:100'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return response()->json([
            'map' => $map->for(trim($station['stop']), (float) $station['lat'], (float) $station['lng']),
        ]);
    }
}
