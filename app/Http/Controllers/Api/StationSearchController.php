<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\KvbApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Nearby's stop picker: KVB stations by name, names that start with the words
 * first, each with every line its points list.
 */
class StationSearchController extends Controller
{
    private const LIMIT = 8;

    public function __invoke(Request $request, KvbApiService $kvb): JsonResponse
    {
        $q = mb_strtolower(trim((string) $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ])['q']));

        $stations = [];
        foreach ($kvb->getStops() as $point) {
            $name = (string) $point['name'];
            $lower = mb_strtolower($name);
            if (! str_contains($lower, $q)) {
                continue;
            }
            $stations[$name] ??= ['name' => $name, 'lines' => [], 'rank' => str_starts_with($lower, $q) ? 0 : 1];
            $stations[$name]['lines'] = array_values(array_unique([...$stations[$name]['lines'], ...$point['lines']]));
        }

        $stations = array_values($stations);
        usort($stations, fn (array $a, array $b) => [$a['rank'], $a['name']] <=> [$b['rank'], $b['name']]);

        return response()->json(['stations' => array_map(function (array $station): array {
            usort($station['lines'], 'strnatcmp');

            return ['name' => $station['name'], 'lines' => $station['lines']];
        }, array_slice($stations, 0, self::LIMIT))]);
    }
}
