<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Transit\NearbyStation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A line's runs at one stop on its route, from that stop's own board
 * (NearbyStation::named): the line map's route asks for each stop as it
 * comes into view, never all at once. Its cancellations and destinations
 * come with it, so a run the live board says is cancelled or goes
 * elsewhere shows as that.
 */
class LineStopController extends Controller
{
    public function __invoke(Request $request, NearbyStation $nearby): JsonResponse
    {
        $asked = $request->validate([
            'stop' => ['required', 'string', 'min:2', 'max:100'],
            'line' => ['required', 'string', 'max:10'],
        ]);

        $station = $nearby->named(trim($asked['stop']));
        if ($station === null) {
            return response()->json(['stop' => null]);
        }

        $line = collect($station['lines'])->firstWhere('line', trim($asked['line']));

        return response()->json(['stop' => [
            'name' => $station['name'],
            'time_basis' => $station['time_basis'],
            'is_stale' => $station['is_stale'],
            'dirs' => $line['dirs'] ?? [],
        ]]);
    }
}
