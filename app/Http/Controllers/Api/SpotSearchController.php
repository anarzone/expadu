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

        $spots = $query
            ->withCount('activeCheckins')
            ->orderByDesc('rating')
            ->limit((int) ($request->query('limit', 50)))
            ->get(['id', 'name', 'category', 'address', 'lat', 'lng', 'wifi_speed', 'noise_level', 'rating', 'time_limit_mins']);

        return response()->json($spots);
    }
}
