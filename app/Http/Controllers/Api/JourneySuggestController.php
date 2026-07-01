<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserPlace;
use App\Services\UserLocationService;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Place;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Journey search suggestions: stations, addresses/streets and POIs from the
 * self-hosted routing geocoder (MOTIS answers in ~10ms and is biased to the
 * user's position), with the user's own saved places matched first. Backs the
 * as-you-type dropdown in the Departures journey planner.
 */
class JourneySuggestController extends Controller
{
    public function __invoke(Request $request, RouteService $routes): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $query = $request->string('q')->toString();
        $user = $request->user();

        $location = app(UserLocationService::class)->resolve($user, $request);
        $bias = new GeoPoint((float) $location['lat'], (float) $location['lng']);

        // The user's own vocabulary ("Home", "Gym") outranks the city.
        $saved = $user->places()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->where('name', 'ILIKE', "%{$query}%")
            ->limit(3)
            ->get()
            ->map(fn (UserPlace $place) => [
                'kind' => 'saved',
                'name' => $place->name,
                'area' => $place->address,
                'lat' => (float) $place->lat,
                'lng' => (float) $place->lng,
                'emoji' => $place->emoji,
            ]);

        $hits = collect($routes->geocode($query, $bias))
            ->map(fn (Place $place) => [
                'kind' => $place->kind ?? 'place',
                'name' => $place->name,
                'area' => $place->area,
                'lat' => $place->point->lat,
                'lng' => $place->point->lng,
                'emoji' => null,
            ])
            ->take(8 - $saved->count());

        return response()->json($saved->concat($hits)->values());
    }
}
