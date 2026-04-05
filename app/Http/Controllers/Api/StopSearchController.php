<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GtfsDepartureService;
use App\Support\RedisLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StopSearchController extends Controller
{
    public function __invoke(Request $request, GtfsDepartureService $gtfsService): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $query = $request->query('q');
        $results = $gtfsService->searchStops($query, 10);

        if ($user = $request->user()) {
            RedisLogger::log("search_debug:{$user->id}", [
                'query' => $query,
                'results' => count($results),
                'source' => 'stop_search',
            ]);
        }

        return response()->json($results);
    }
}
