<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VrsTriasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TransferConnectionsController extends Controller
{
    /**
     * Get available connections at a transfer stop.
     * Returns departure lines the user can choose from.
     */
    public function getConnections(Request $request, VrsTriasService $trias): JsonResponse
    {
        $validated = $request->validate([
            'stop_id' => ['required', 'string'],
            'after_time' => ['nullable', 'string'],
            'current_line' => ['nullable', 'string'],
            'destination_name' => ['nullable', 'string'],
        ]);

        $departures = $trias->getDeparturesAtStop($validated['stop_id'], 15);

        if (! $departures) {
            return response()->json(['connections' => [], 'error' => 'unavailable']);
        }

        $currentLine = $validated['current_line'] ?? '';
        $destName = mb_strtolower($validated['destination_name'] ?? '');

        $connections = [];
        $seenLines = [];

        foreach ($departures as $dep) {
            $line = $dep['line'];
            $direction = $dep['direction'];
            $key = $line.'_'.$direction;

            // Skip duplicates and the current line
            if (isset($seenLines[$key])) {
                continue;
            }
            $seenLines[$key] = true;

            // Check if this line heads toward the destination
            $towardDest = false;
            if ($destName) {
                $dirLower = mb_strtolower($direction);
                $towardDest = str_contains($dirLower, $destName) || str_contains($destName, $dirLower);
            }

            $nextMin = $dep['departures'][0] ?? null;
            $depTime = $nextMin !== null ? Carbon::now()->addMinutes($nextMin)->format('H:i') : '';

            $connections[] = [
                'line' => $line,
                'direction' => $direction,
                'departure_time' => $depTime,
                'minutes' => $nextMin,
                'type' => $dep['type'],
                'is_current' => $line === $currentLine,
                'toward_destination' => $towardDest,
            ];
        }

        // Sort: current line first, then toward-destination, then by departure time
        usort($connections, function ($a, $b) {
            if ($a['is_current'] !== $b['is_current']) {
                return $b['is_current'] <=> $a['is_current'];
            }
            if ($a['toward_destination'] !== $b['toward_destination']) {
                return $b['toward_destination'] <=> $a['toward_destination'];
            }

            return ($a['minutes'] ?? 999) <=> ($b['minutes'] ?? 999);
        });

        return response()->json(['connections' => $connections]);
    }

    /**
     * User selected an alternative connection — re-plan from transfer stop to destination.
     * Stitches original segments before the transfer with new segments after.
     */
    public function selectConnection(Request $request, VrsTriasService $trias): JsonResponse
    {
        $validated = $request->validate([
            'stop_id' => ['required', 'string'],
            'departure_time' => ['required', 'string'],
            'to_lat' => ['required', 'numeric', 'between:-90,90'],
            'to_lng' => ['required', 'numeric', 'between:-180,180'],
            'to_name' => ['nullable', 'string'],
            'segments_before' => ['required', 'array'],
        ]);

        $toLat = (float) $validated['to_lat'];
        $toLng = (float) $validated['to_lng'];

        // Plan from the transfer stop to the destination
        $result = $trias->planJourneyFromStop(
            $validated['stop_id'],
            $toLat,
            $toLng,
            $validated['departure_time'],
            3,
        );

        if (! $result || empty($result['trips'])) {
            return response()->json(['error' => 'No route found from this connection'], 404);
        }

        $newTrip = $result['trips'][0];
        $segmentsBefore = $validated['segments_before'];

        // Stitch: original segments before transfer + new trip segments
        $allSegments = array_merge($segmentsBefore, $newTrip['segments']);

        // Build combined steps from segments
        $steps = [];
        foreach ($segmentsBefore as $seg) {
            if (($seg['type'] ?? '') === 'walk') {
                $steps[] = [
                    'instruction' => 'Walk '.($seg['duration_min'] ?? '?').' min',
                    'distance_km' => $seg['distance_km'] ?? 0,
                    'time_sec' => ($seg['duration_min'] ?? 0) * 60,
                    'type' => 'walk',
                    'emoji' => '🚶',
                ];
            } elseif (($seg['type'] ?? '') === 'transit') {
                $steps[] = [
                    'instruction' => 'Board Line '.($seg['line'] ?? '?').' at '.($seg['boarding_stop'] ?? '?'),
                    'distance_km' => 0,
                    'time_sec' => 0,
                    'type' => 'board',
                    'emoji' => '🚋',
                    'detail' => 'Dep '.($seg['departure_time'] ?? ''),
                ];
                $steps[] = [
                    'instruction' => 'Ride to '.($seg['alighting_stop'] ?? '?'),
                    'distance_km' => 0,
                    'time_sec' => ($seg['duration_min'] ?? 0) * 60,
                    'type' => 'ride',
                    'emoji' => '🚋',
                    'detail' => 'Arr '.($seg['arrival_time'] ?? ''),
                ];
            }
        }

        // Add transfer step
        $steps[] = [
            'instruction' => 'Transfer',
            'distance_km' => 0,
            'time_sec' => 60,
            'type' => 'transfer',
            'emoji' => '🔄',
        ];

        // Add new trip steps
        $steps = array_merge($steps, $newTrip['steps']);

        // Calculate total duration
        $firstDep = null;
        $lastArr = null;
        foreach ($allSegments as $seg) {
            if (($seg['type'] ?? '') === 'transit') {
                if (! $firstDep && ! empty($seg['departure_time'])) {
                    $firstDep = $seg['departure_time'];
                }
                if (! empty($seg['arrival_time'])) {
                    $lastArr = $seg['arrival_time'];
                }
            }
        }

        $walkMin = collect($allSegments)->where('type', 'walk')->sum('duration_min');
        $transitMin = 0;
        if ($firstDep && $lastArr) {
            $transitMin = max(0, (int) round((Carbon::parse($lastArr)->timestamp - Carbon::parse($firstDep)->timestamp) / 60));
        }
        $totalMin = $walkMin + $transitMin;

        $transfers = max(0, collect($allSegments)->where('type', 'transit')->count() - 1);
        $lines = collect($allSegments)->where('type', 'transit')->pluck('line')->implode(' → ');

        return response()->json([
            'mode' => 'transit',
            'source' => 'trias',
            'duration_min' => $totalMin,
            'distance_km' => 0,
            'departure_time' => $firstDep ?? '',
            'arrival_time' => $lastArr ?? '',
            'transfers' => $transfers,
            'route_label' => $lines,
            'segments' => $allSegments,
            'steps' => $steps,
            'later_departures' => [],
            'trip_alternatives' => [],
            'geometry' => '',
        ]);
    }
}
