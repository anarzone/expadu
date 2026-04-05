<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GeocodingService;
use App\Support\RedisLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeocodeController extends Controller
{
    public function __invoke(Request $request, GeocodingService $geocoding): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $query = $request->string('q')->toString();
        $results = $geocoding->search($query);

        if ($user = $request->user()) {
            RedisLogger::log("search_debug:{$user->id}", [
                'query' => $query,
                'results' => count($results),
                'source' => 'geocode',
            ]);
        }

        return response()->json($results);
    }
}
