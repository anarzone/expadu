<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Spot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpotSearchController extends Controller
{
    /**
     * Return spots within a bounding box (map viewport).
     * GET /api/spots?sw_lat=&sw_lng=&ne_lat=&ne_lng=&category=&limit=
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'sw_lat' => ['required', 'numeric'],
            'sw_lng' => ['required', 'numeric'],
            'ne_lat' => ['required', 'numeric'],
            'ne_lng' => ['required', 'numeric'],
            'category' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        // User's location for distance calculation
        $user = $request->user();
        $home = $user?->places()->where('category', 'home')->first();
        $userLat = $home?->lat ? (float) $home->lat : 50.9375;
        $userLng = $home?->lng ? (float) $home->lng : 6.9603;

        $query = Spot::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->where('lat', '>=', $request->query('sw_lat'))
            ->where('lat', '<=', $request->query('ne_lat'))
            ->where('lng', '>=', $request->query('sw_lng'))
            ->where('lng', '<=', $request->query('ne_lng'));

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        // Add distance calculation and sort by distance
        $spots = $query
            ->withCount('activeCheckins')
            ->selectRaw('*, (6371 * acos(cos(radians(?)) * cos(radians(lat)) * cos(radians(lng) - radians(?)) + sin(radians(?)) * sin(radians(lat)))) as distance_km', [$userLat, $userLng, $userLat])
            ->orderBy('distance_km')
            ->limit((int) ($request->query('limit', 50)))
            ->get();

        return response()->json($spots->map(fn (Spot $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'category' => $s->category instanceof \BackedEnum ? $s->category->value : $s->category,
            'address' => $s->address,
            'lat' => (float) $s->lat,
            'lng' => (float) $s->lng,
            'wifi_speed' => $s->wifi_speed,
            'noise_level' => $s->noise_level instanceof \BackedEnum ? $s->noise_level->value : $s->noise_level,
            'rating' => $s->rating,
            'time_limit_mins' => $s->time_limit_mins,
            'active_checkins_count' => $s->active_checkins_count ?? 0,
            'distance_km' => round((float) $s->distance_km, 1),
        ]));
    }
}
