<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DisruptionService;
use App\Services\GtfsDepartureService;
use App\Services\LocationPatternService;
use App\Services\NearbyStopService;
use App\Services\ValhallaRoutingService;
use App\Services\WeatherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Computes route options (bike, transit, walk, drive) from user's current location to a destination.
 * Uses Valhalla for real routing when available, falls back to haversine estimates.
 */
class RouteOptionsController extends Controller
{
    public function __invoke(Request $request, ValhallaRoutingService $valhalla): JsonResponse
    {
        $validated = $request->validate([
            'to_lat' => ['required', 'numeric', 'between:-90,90'],
            'to_lng' => ['required', 'numeric', 'between:-180,180'],
            'name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'mode' => ['nullable', 'string', 'in:pedestrian,bicycle,auto,transit'],
        ]);

        $user = $request->user();
        $toLat = (float) $validated['to_lat'];
        $toLng = (float) $validated['to_lng'];
        $toName = $validated['name'] ?? 'Destination';
        $toAddress = $validated['address'] ?? null;

        // Determine user's current location: GPS ping → Home fallback
        $locationService = app(LocationPatternService::class);
        $currentLoc = $locationService->detectCurrentLocation($user);
        $home = $user->places()->where('category', 'home')->first();

        if ($currentLoc) {
            $fromName = $currentLoc['place_name'];
            $fromPlace = $user->places()->where('name', $fromName)->first();
            $fromLat = $fromPlace?->lat ? (float) $fromPlace->lat : ($home?->lat ? (float) $home->lat : 50.9375);
            $fromLng = $fromPlace?->lng ? (float) $fromPlace->lng : ($home?->lng ? (float) $home->lng : 6.9603);
        } else {
            $fromName = $home?->name ?? 'Home';
            $fromLat = $home?->lat ? (float) $home->lat : 50.9375;
            $fromLng = $home?->lng ? (float) $home->lng : 6.9603;
        }

        // Single mode request — return full route with geometry + steps
        if (isset($validated['mode'])) {
            // Transit: 3-segment walk→ride→walk route
            if ($validated['mode'] === 'transit') {
                return $this->buildTransitRoute($valhalla, $fromLat, $fromLng, $toLat, $toLng, $fromName, $toName, $toAddress);
            }

            $route = $valhalla->route($fromLat, $fromLng, $toLat, $toLng, $validated['mode']);

            if (! $route) {
                return response()->json(['error' => 'Route not available'], 404);
            }

            return response()->json([
                'from' => ['name' => $fromName, 'lat' => $fromLat, 'lng' => $fromLng],
                'to' => ['name' => $toName, 'lat' => $toLat, 'lng' => $toLng, 'address' => $toAddress],
                'mode' => $validated['mode'],
                'duration_min' => $route['duration_min'],
                'distance_km' => $route['distance_km'],
                'geometry' => $route['geometry'],
                'steps' => $route['steps'],
            ]);
        }

        // Multi-mode comparison — return all options
        $distKm = $this->haversineKm($fromLat, $fromLng, $toLat, $toLng);

        // Weather for bike recommendation
        $weather = app(WeatherService::class)->getCurrentWeather($fromLat, $fromLng);
        $forecast = app(WeatherService::class)->getForecast($fromLat, $fromLng);
        $temp = $weather['temperature'] ?? 10;
        $wind = $weather['wind_speed'] ?? 0;
        $condition = $weather['condition'] ?? 'Cloudy';
        $raining = ($weather['precipitation'] ?? 0) > 0.5;
        $bikeScore = $forecast['bike_score'] ?? '';

        // Try Valhalla for real times, fall back to estimates
        $useValhalla = $valhalla->isAvailable();

        $bikeRoute = $useValhalla ? $valhalla->route($fromLat, $fromLng, $toLat, $toLng, 'bicycle') : null;
        $walkRoute = $useValhalla ? $valhalla->route($fromLat, $fromLng, $toLat, $toLng, 'pedestrian') : null;
        $driveRoute = $useValhalla ? $valhalla->route($fromLat, $fromLng, $toLat, $toLng, 'auto') : null;

        $bikeTime = $bikeRoute ? $bikeRoute['duration_min'] : max(2, (int) round(($distKm / 15) * 60));
        $walkTime = $walkRoute ? $walkRoute['duration_min'] : max(3, (int) round(($distKm / 5) * 60));
        $driveTime = $driveRoute ? $driveRoute['duration_min'] : max(2, (int) round(($distKm / 40) * 60));
        $bikeDist = $bikeRoute ? $bikeRoute['distance_km'] : round($distKm, 1);
        $walkDist = $walkRoute ? $walkRoute['distance_km'] : round($distKm, 1);
        $driveDist = $driveRoute ? $driveRoute['distance_km'] : round($distKm, 1);

        // Transit options from GTFS
        $gtfs = App::make(GtfsDepartureService::class);
        $departures = $gtfs->getDeparturesNearby($fromLat, $fromLng, 5);
        $disruptedLines = app(DisruptionService::class)->getDisruptedLines();

        $transitOption = null;
        foreach ($departures['departures'] ?? [] as $dep) {
            $nextMin = $dep['departures'][0] ?? null;
            if ($nextMin === null) {
                continue;
            }
            $line = $dep['line'];
            $isDisrupted = isset($disruptedLines[$line]);
            $following = array_values(array_filter(array_slice($dep['departures'], 1, 3), fn ($t) => $t > 0));

            $transitOption = [
                'mode' => 'transit',
                'emoji' => '🚋',
                'line' => $line,
                'direction' => $dep['direction'],
                'time' => $nextMin,
                'distance_km' => null,
                'detail' => $isDisrupted
                    ? 'Disrupted · Use alternative'
                    : ($following ? 'Then in '.implode(', ', $following).' min' : 'On time'),
                'best' => false,
                'disrupted' => $isDisrupted,
                'geometry' => null,
            ];
            break;
        }

        // Smart mode recommendation based on context
        $hour = now()->hour;
        $isLateNight = $hour >= 22 || $hour < 5;
        $transitDisrupted = $transitOption && ($transitOption['disrupted'] ?? false);
        $bikeIsGood = (str_starts_with($bikeScore, 'Great') || str_starts_with($bikeScore, 'Good'))
            && ! $raining && $bikeTime <= 30 && $wind < 30;

        // Determine best + reason
        $bestMode = 'bike';
        $bestReason = 'Best for today\'s weather';

        if ($distKm < 1 && $walkTime <= 15) {
            $bestMode = 'walk';
            $bestReason = 'Short walk — just '.number_format($walkDist * 1000, 0).'m';
        } elseif ($raining || $wind >= 30) {
            $bestMode = $transitDisrupted ? 'drive' : ($transitOption ? 'transit' : 'drive');
            $bestReason = $raining ? 'Rain expected — avoid cycling' : 'Strong wind — take shelter';
        } elseif ($isLateNight) {
            $bestMode = 'drive';
            $bestReason = 'Late night — drive is safest';
        } elseif ($transitDisrupted && $bikeIsGood) {
            $bestMode = 'bike';
            $bestReason = 'Transit disrupted — bike instead';
        } elseif ($bikeIsGood) {
            $bestMode = 'bike';
            $bestReason = "Clear weather · {$temp}°C";
        } elseif ($transitOption && ! $transitDisrupted) {
            $bestMode = 'transit';
            $bestReason = 'Good transit connection';
        }

        $options = [];

        $options[] = [
            'mode' => 'bike',
            'emoji' => '🚲',
            'time' => $bikeTime,
            'distance_km' => $bikeDist,
            'detail' => "{$condition} · {$temp}°C".($bikeRoute ? '' : ' · estimated'),
            'best' => $bestMode === 'bike',
            'recommendation_reason' => $bestMode === 'bike' ? $bestReason : null,
            'geometry' => $bikeRoute['geometry'] ?? null,
        ];

        if ($transitOption) {
            $transitOption['best'] = $bestMode === 'transit';
            $transitOption['recommendation_reason'] = $bestMode === 'transit' ? $bestReason : null;
            $options[] = $transitOption;
        }

        $options[] = [
            'mode' => 'walk',
            'emoji' => '🚶',
            'time' => $walkTime,
            'distance_km' => $walkDist,
            'detail' => $walkDist < 1 ? 'Short walk' : "{$walkDist} km",
            'best' => $bestMode === 'walk',
            'recommendation_reason' => $bestMode === 'walk' ? $bestReason : null,
            'geometry' => $walkRoute['geometry'] ?? null,
        ];

        $options[] = [
            'mode' => 'drive',
            'emoji' => '🚗',
            'time' => $driveTime,
            'distance_km' => $driveDist,
            'detail' => "{$driveDist} km",
            'best' => $bestMode === 'drive',
            'recommendation_reason' => $bestMode === 'drive' ? $bestReason : null,
            'geometry' => $driveRoute['geometry'] ?? null,
        ];

        return response()->json([
            'from' => ['name' => $fromName, 'lat' => $fromLat, 'lng' => $fromLng],
            'to' => ['name' => $toName, 'lat' => $toLat, 'lng' => $toLng, 'address' => $toAddress],
            'distance_km' => round($distKm, 1),
            'routing_source' => $useValhalla ? 'valhalla' : 'estimated',
            'options' => $options,
            'maps_url' => [
                'google' => "https://www.google.com/maps/dir/?api=1&origin={$fromLat},{$fromLng}&destination={$toLat},{$toLng}&travelmode=transit",
                'apple' => "https://maps.apple.com/?saddr={$fromLat},{$fromLng}&daddr={$toLat},{$toLng}&dirflg=r",
            ],
        ]);
    }

    /**
     * Build a 3-segment transit route: walk → ride → walk.
     */
    private function buildTransitRoute(
        ValhallaRoutingService $valhalla,
        float $fromLat, float $fromLng,
        float $toLat, float $toLng,
        string $fromName, string $toName, ?string $toAddress,
    ): JsonResponse {
        $nearbyService = app(NearbyStopService::class);
        $gtfs = app(GtfsDepartureService::class);

        // Find nearest stops to origin and destination
        $originStops = $nearbyService->getWalkableStops($fromLat, $fromLng, 800);
        $destStops = $nearbyService->getWalkableStops($toLat, $toLng, 800);

        if (empty($originStops) || empty($destStops)) {
            return response()->json(['error' => 'No transit stops nearby'], 404);
        }

        // Try combinations of origin/dest stops to find a connecting line
        $originStop = $originStops[0];
        $destStop = $destStops[0];
        $transitLine = null;
        $transitDirection = null;
        $transitTime = null;
        $routeStops = null;

        // Get departures from origin stops, try to find a line that also serves a destination stop
        foreach (array_slice($originStops, 0, 3) as $oStop) {
            $departures = $gtfs->getDepartures($oStop['name'], 10);
            foreach ($departures['departures'] ?? [] as $dep) {
                foreach (array_slice($destStops, 0, 3) as $dStop) {
                    $tryRoute = $gtfs->getRouteStopSequence($dep['line'], $oStop['name'], $dStop['name']);
                    if ($tryRoute && count($tryRoute['stops']) >= 2) {
                        $originStop = $oStop;
                        $destStop = $dStop;
                        $transitLine = $dep['line'];
                        $transitDirection = $dep['direction'];
                        $transitTime = $dep['departures'][0] ?? null;
                        $routeStops = $tryRoute;
                        break 3;
                    }
                }
            }
        }

        // Fallback: just use the first departure from the nearest stop
        if (! $transitLine) {
            $departures = $gtfs->getDepartures($originStops[0]['name'], 5);
            $firstDep = $departures['departures'][0] ?? null;
            if ($firstDep) {
                $transitLine = $firstDep['line'];
                $transitDirection = $firstDep['direction'];
                $transitTime = $firstDep['departures'][0] ?? null;
            }
        }

        // Walk segment 1: origin → boarding stop
        $walk1 = $valhalla->route($fromLat, $fromLng, $originStop['lat'], $originStop['lng'], 'pedestrian');

        // Walk segment 2: alighting stop → destination
        $walk2 = $valhalla->route($destStop['lat'], $destStop['lng'], $toLat, $toLng, 'pedestrian');

        // Build segments
        $segments = [];

        if ($walk1 && $walk1['duration_min'] > 0) {
            $segments[] = [
                'type' => 'walk',
                'geometry' => $walk1['geometry'],
                'duration_min' => $walk1['duration_min'],
                'distance_km' => $walk1['distance_km'],
            ];
        }

        // Transit segment: use stop coordinates
        $transitCoords = [];
        $transitStopNames = [];
        if ($routeStops && ! empty($routeStops['stops'])) {
            foreach ($routeStops['stops'] as $s) {
                $transitCoords[] = [$s['lng'], $s['lat']];
                $transitStopNames[] = $s['name'];
            }
        } else {
            // Fallback: straight line between stops
            $transitCoords = [
                [$originStop['lng'], $originStop['lat']],
                [$destStop['lng'], $destStop['lat']],
            ];
            $transitStopNames = [$originStop['name'], $destStop['name']];
        }

        $segments[] = [
            'type' => 'transit',
            'line' => $transitLine ?? '?',
            'direction' => $transitDirection,
            'color' => $routeStops['color'] ?? '#1A4CD4',
            'coordinates' => $transitCoords,
            'stop_names' => $transitStopNames,
            'duration_min' => $transitTime ? max(1, (int) round(count($transitStopNames) * 2)) : 10,
            'next_departure_min' => $transitTime,
        ];

        if ($walk2 && $walk2['duration_min'] > 0) {
            $segments[] = [
                'type' => 'walk',
                'geometry' => $walk2['geometry'],
                'duration_min' => $walk2['duration_min'],
                'distance_km' => $walk2['distance_km'],
            ];
        }

        $totalMin = array_sum(array_column($segments, 'duration_min'));
        if ($transitTime) {
            $totalMin += $transitTime; // add wait time
        }

        // Build steps
        $steps = [];
        foreach ($segments as $seg) {
            if ($seg['type'] === 'walk') {
                $steps[] = [
                    'instruction' => "Walk {$seg['duration_min']} min ({$seg['distance_km']} km)",
                    'distance_km' => $seg['distance_km'],
                    'time_sec' => $seg['duration_min'] * 60,
                    'type' => 'depart',
                    'emoji' => '🚶',
                ];
            } else {
                $stopCount = count($seg['stop_names']);
                $lineLabel = $seg['line'] !== '?' ? "Line {$seg['line']}" : 'Transit';
                $dirLabel = $seg['direction'] ? " → {$seg['direction']}" : '';
                $steps[] = [
                    'instruction' => "{$lineLabel}{$dirLabel} ({$stopCount} stops)",
                    'distance_km' => 0,
                    'time_sec' => $seg['duration_min'] * 60,
                    'type' => 'continue',
                    'emoji' => '🚋',
                ];
                if ($seg['next_departure_min'] !== null) {
                    $steps[] = [
                        'instruction' => "Next departure in {$seg['next_departure_min']} min",
                        'distance_km' => 0,
                        'time_sec' => 0,
                        'type' => 'continue',
                        'emoji' => '🕐',
                    ];
                }
            }
        }

        return response()->json([
            'from' => ['name' => $fromName, 'lat' => $fromLat, 'lng' => $fromLng],
            'to' => ['name' => $toName, 'lat' => $toLat, 'lng' => $toLng, 'address' => $toAddress],
            'mode' => 'transit',
            'duration_min' => $totalMin,
            'distance_km' => round($this->haversineKm($fromLat, $fromLng, $toLat, $toLng), 1),
            'segments' => $segments,
            'steps' => $steps,
            'geometry' => '', // no single geometry for transit
        ]);
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
