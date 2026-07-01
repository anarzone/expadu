<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JourneyRecent;
use App\Models\UserPlace;
use App\Services\UserLocationService;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Place;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Journey search suggestions.
 *
 * With a query: stations, addresses/streets and POIs from the self-hosted
 * routing geocoder (~10ms, biased to the user's position), the user's own
 * saved places matched first.
 *
 * Without a query (field focused, nothing typed yet): Google-Maps-style
 * defaults — the user's recent origins/destinations plus saved places.
 */
class JourneySuggestController extends Controller
{
    public function __invoke(Request $request, RouteService $routes): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'in:origin,destination'],
        ]);

        $query = trim((string) ($validated['q'] ?? ''));
        $user = $request->user();

        if (mb_strlen($query) < 2) {
            return response()->json($this->defaults($user->id, $validated['role'] ?? 'destination'));
        }

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

    /**
     * Pre-typing defaults: recents for the field's role first (most recent
     * wins), then saved places — deduplicated by name.
     *
     * @return list<array<string, mixed>>
     */
    private function defaults(int $userId, string $role): array
    {
        $recents = JourneyRecent::query()
            ->where('user_id', $userId)
            ->where('role', $role)
            ->orderByDesc('last_used_at')
            ->limit(6)
            ->get()
            ->map(fn (JourneyRecent $recent) => [
                'kind' => 'recent',
                'name' => $recent->name,
                'area' => $recent->area,
                'lat' => $recent->lat,
                'lng' => $recent->lng,
                'emoji' => null,
            ]);

        $saved = UserPlace::query()
            ->where('user_id', $userId)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderBy('sort_order')
            ->limit(4)
            ->get()
            ->map(fn (UserPlace $place) => [
                'kind' => 'saved',
                'name' => $place->name,
                'area' => $place->address,
                'lat' => (float) $place->lat,
                'lng' => (float) $place->lng,
                'emoji' => $place->emoji,
            ]);

        return $recents->concat($saved)
            ->unique(fn (array $row) => mb_strtolower($row['name']))
            ->take(8)
            ->values()
            ->all();
    }
}
