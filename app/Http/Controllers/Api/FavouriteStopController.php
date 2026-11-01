<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FavouriteStop;
use App\Services\KvbApiService;
use App\Transit\FavouriteStops;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Travel's ★ favourite stops for the signed-in account. Only a real KVB
 * station can be starred (FavouriteStops, which Composer stars through too),
 * and a favourite is only ever read or removed by the account that made it.
 */
class FavouriteStopController extends Controller
{
    public function index(Request $request, KvbApiService $kvb): JsonResponse
    {
        return response()->json(['favourites' => self::forUser($request->user()->id, $kvb)]);
    }

    public function store(Request $request, KvbApiService $kvb, FavouriteStops $stops): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $userId = $request->user()->id;

        $added = $stops->add($userId, $validated['name']);

        return response()->json(['favourites' => self::forUser($userId, $kvb)], $added ? 201 : 200);
    }

    public function destroy(Request $request, KvbApiService $kvb, FavouriteStops $stops): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $userId = $request->user()->id;

        $stops->remove($userId, $validated['name']);

        return response()->json(['favourites' => self::forUser($userId, $kvb)]);
    }

    /**
     * The account's favourites with where each station is, oldest first. A
     * stop KVB no longer lists stays, without a place.
     *
     * @return list<array{name: string, lat: float|null, lng: float|null, lines: list<string>}>
     */
    public static function forUser(int $userId, KvbApiService $kvb): array
    {
        return FavouriteStop::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->pluck('name')
            ->map(function (string $name) use ($kvb): array {
                $points = $kvb->stationPoints($name);
                $lines = array_values(array_unique(array_merge(...array_map(fn ($p) => $p['lines'], $points ?: [['lines' => []]]))));
                usort($lines, 'strnatcmp');

                return [
                    'name' => $name,
                    'lat' => $points === [] ? null : round(array_sum(array_column($points, 'lat')) / count($points), 6),
                    'lng' => $points === [] ? null : round(array_sum(array_column($points, 'lng')) / count($points), 6),
                    'lines' => $lines,
                ];
            })
            ->all();
    }
}
