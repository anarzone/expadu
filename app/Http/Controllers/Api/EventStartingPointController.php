<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GeocodingService;
use App\Support\EventStartingPoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suggestions for the Events page's starting point. The text comes in a
 * POST body, so what someone types (often their own address) stays out of
 * URLs and access logs, and nothing here records it. A suggestion that is
 * a district, stop or venue carries a `key` the page may put in its URL;
 * the rest stay in the page's state.
 */
class EventStartingPointController extends Controller
{
    public function __invoke(Request $request, GeocodingService $geocoding, EventStartingPoint $points): JsonResponse
    {
        $input = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
        ]);
        $q = trim($input['q']);

        // Rows that would look the same in the list (the geocoder's city
        // aside) collapse into the first.
        $found = collect($geocoding->search($q))
            ->unique(fn (array $row) => mb_strtolower("{$row['name']}|{$row['street']}"))
            ->take(5)
            ->values()
            ->all();
        $public = $points->publicMany([$q, ...array_column($found, 'name')]);

        $rows = array_map(fn (array $row) => [
            'name' => $row['name'],
            'street' => $row['street'],
            'lat' => $row['lat'],
            'lng' => $row['lng'],
            'key' => $public[mb_strtolower(trim($row['name']))]['key'] ?? null,
        ], $found);

        // A district, stop or venue named exactly leads, even when the
        // geocoder doesn't know it.
        $exact = $public[mb_strtolower($q)] ?? null;

        if ($exact !== null && ! in_array($exact['key'], array_column($rows, 'key'), true)) {
            array_unshift($rows, [...$exact, 'street' => null]);
        }

        return response()->json(array_slice($rows, 0, 5));
    }
}
