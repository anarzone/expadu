<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DisruptionService;
use App\Services\GtfsDepartureService;
use App\Services\NearbyStopService;
use App\Services\UserLocationService;
use App\Services\ValhallaRoutingService;
use App\Services\VrsTriasService;
use App\Services\WeatherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;

/**
 * Computes route options (bike, transit, walk, drive) from user's current location to a destination.
 * Uses Valhalla for real routing when available, falls back to haversine estimates.
 */
class RouteOptionsController extends Controller
{
    public function __invoke(Request $request, ValhallaRoutingService $valhalla, VrsTriasService $trias): JsonResponse
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

        // Resolve user's current location: GPS → Redis ping → Home → default
        $location = app(UserLocationService::class)->resolve($user, $request);
        $fromName = $location['name'];
        $fromLat = $location['lat'];
        $fromLng = $location['lng'];

        // Single mode request — return full route with geometry + steps
        if (isset($validated['mode'])) {
            // Transit: TRIAS journey planning with GTFS fallback
            if ($validated['mode'] === 'transit') {
                return $this->buildTransitRoute($valhalla, $trias, $fromLat, $fromLng, $toLat, $toLng, $fromName, $toName, $toAddress);
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

        // Transit option: try TRIAS journey planning first, fall back to GTFS departures
        $transitOption = null;
        $triasResult = $trias->planJourney($fromLat, $fromLng, $toLat, $toLng, 1);
        if ($triasResult && ! empty($triasResult['trips'])) {
            $firstTrip = $triasResult['trips'][0];
            $transferLabel = $firstTrip['transfers'] > 0
                ? "{$firstTrip['transfers']} transfer".($firstTrip['transfers'] > 1 ? 's' : '')
                : 'Direct';
            $transitOption = [
                'mode' => 'transit',
                'emoji' => '🚋',
                'time' => $firstTrip['total_duration_min'],
                'distance_km' => null,
                'detail' => "Dep {$firstTrip['departure_time']} · {$transferLabel}",
                'best' => false,
                'disrupted' => false,
                'geometry' => null,
            ];
        }

        // Fallback: use GTFS departures for transit option
        if (! $transitOption) {
            $gtfs = App::make(GtfsDepartureService::class);
            $departures = $gtfs->getDeparturesNearby($fromLat, $fromLng, 5);
            $disruptedLines = app(DisruptionService::class)->getDisruptedLines();

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
     * Build transit route using TRIAS journey planning, with GTFS fallback.
     */
    private function buildTransitRoute(
        ValhallaRoutingService $valhalla,
        VrsTriasService $trias,
        float $fromLat, float $fromLng,
        float $toLat, float $toLng,
        string $fromName, string $toName, ?string $toAddress,
    ): JsonResponse {
        // Try TRIAS first — request extra results to find different routes
        $triasResult = $trias->planJourney($fromLat, $fromLng, $toLat, $toLng, 10);

        if ($triasResult && ! empty($triasResult['trips'])) {
            // Collect all unique transfer stops from initial results
            $transferStops = [];
            foreach ($triasResult['trips'] as $trip) {
                $segs = $trip['segments'];
                for ($j = 0; $j < count($segs); $j++) {
                    if (($segs[$j]['type'] ?? '') !== 'transit' || empty($segs[$j]['alighting_stop_id'])) {
                        continue;
                    }
                    // Check if there's another transit segment after this one (= transfer point)
                    $hasNextTransit = false;
                    for ($k = $j + 1; $k < count($segs); $k++) {
                        if (($segs[$k]['type'] ?? '') === 'transit') {
                            $hasNextTransit = true;
                            break;
                        }
                    }
                    if ($hasNextTransit) {
                        $rawId = $segs[$j]['alighting_stop_id'];
                        $parentId = preg_match('/^(de:\d+:\d+)/', $rawId, $m) ? $m[1] : $rawId;
                        if (! isset($transferStops[$parentId])) {
                            $transferStops[$parentId] = [
                                'name' => $segs[$j]['alighting_stop'],
                                'arrival_time' => $segs[$j]['arrival_time'] ?? '',
                                'segments_before' => array_slice($segs, 0, $j + 1),
                                'steps_before' => $this->buildStepsForSegments(array_slice($segs, 0, $j + 1)),
                            ];
                        }
                    }
                }
            }

            // For each transfer stop, query alternative routes to destination
            $allTrips = $triasResult['trips'];

            foreach ($transferStops as $stopId => $info) {
                // Use arrival time at transfer stop (or now+5min as fallback)
                $depTime = ! empty($info['arrival_time'])
                    ? Carbon::parse($info['arrival_time'])->toIso8601String()
                    : now()->addMinutes(5)->toIso8601String();
                $altResult = $trias->planJourneyFromStop($stopId, $toLat, $toLng, $depTime, 10);
                if (! $altResult || empty($altResult['trips'])) {
                    continue;
                }

                foreach ($altResult['trips'] as $altTrip) {
                    // Stitch: original segments before transfer + new trip segments
                    $stitchedSegments = array_merge($info['segments_before'], $altTrip['segments']);
                    $stitchedSteps = array_merge($info['steps_before'], [
                        ['instruction' => 'Transfer', 'distance_km' => 0, 'time_sec' => 60, 'type' => 'transfer', 'emoji' => '🔄'],
                    ], $altTrip['steps']);

                    // Calculate times from the full stitched route
                    $allTransitSegs = collect($stitchedSegments)->where('type', 'transit');
                    $firstDep = $allTransitSegs->pluck('departure_time')->first();
                    $lastArr = $allTransitSegs->pluck('arrival_time')->last();
                    $walkMin = (int) collect($stitchedSegments)->where('type', 'walk')->sum('duration_min');
                    $transitMin = ($firstDep && $lastArr) ? max(0, (int) round((Carbon::parse($lastArr)->timestamp - Carbon::parse($firstDep)->timestamp) / 60)) : 0;
                    $transfers = max(0, $allTransitSegs->count() - 1);

                    // Use original trip's overall departure (including walk to first stop)
                    $origFirstSeg = collect($info['segments_before'])->first();
                    $walkBeforeMin = ($origFirstSeg && ($origFirstSeg['type'] ?? '') === 'walk') ? ($origFirstSeg['duration_min'] ?? 0) : 0;
                    $overallDep = $firstDep ? Carbon::parse($firstDep)->subMinutes($walkBeforeMin)->format('H:i') : $altTrip['departure_time'];
                    $overallArr = $lastArr ?? $altTrip['arrival_time'];
                    // Add walk after last transit if altTrip has one
                    $lastAltSeg = collect($altTrip['segments'])->last();
                    $walkAfterMin = ($lastAltSeg && ($lastAltSeg['type'] ?? '') === 'walk') ? ($lastAltSeg['duration_min'] ?? 0) : 0;

                    $allTrips[] = [
                        'departure_time' => $overallDep,
                        'arrival_time' => $lastArr ? Carbon::parse($lastArr)->addMinutes($walkAfterMin)->format('H:i') : $overallArr,
                        'total_duration_min' => $walkMin + $transitMin + $walkBeforeMin,
                        'transfers' => $transfers,
                        'segments' => $stitchedSegments,
                        'steps' => $stitchedSteps,
                    ];
                }
            }

            // Deduplicate by line combination and sort by departure time
            $seen = [];
            $uniqueTrips = [];
            foreach ($allTrips as $trip) {
                $lineKey = collect($trip['segments'])->where('type', 'transit')->map(fn ($s) => $s['line'].'@'.$s['boarding_stop'])->implode('→');
                if (isset($seen[$lineKey])) {
                    continue;
                }
                $seen[$lineKey] = true;
                $uniqueTrips[] = $trip;
            }

            // Sort: trains/trams first, buses last, then by duration
            $modeOrder = ['rail' => 0, 'subway' => 1, 'tram' => 2, 'bus' => 3];
            usort($uniqueTrips, function ($a, $b) use ($modeOrder) {
                $aMode = collect($a['segments'])->where('type', 'transit')->pluck('mode')->first() ?? 'bus';
                $bMode = collect($b['segments'])->where('type', 'transit')->pluck('mode')->first() ?? 'bus';
                $aOrder = $modeOrder[$aMode] ?? 3;
                $bOrder = $modeOrder[$bMode] ?? 3;
                if ($aOrder !== $bOrder) {
                    return $aOrder <=> $bOrder;
                }

                return $a['total_duration_min'] <=> $b['total_duration_min'];
            });

            // Build trip options for frontend
            $tripOptions = [];
            foreach (array_slice($uniqueTrips, 0, 10) as $trip) {
                $transitSegs = collect($trip['segments'])->where('type', 'transit');
                $firstTransit = $transitSegs->first();
                $walkMin = collect($trip['segments'])->where('type', 'walk')->sum('duration_min');

                $legIcons = [];
                foreach ($trip['segments'] as $seg) {
                    if ($seg['type'] === 'walk') {
                        $legIcons[] = ['type' => 'walk'];
                    } elseif ($seg['type'] === 'transit') {
                        $legIcons[] = ['type' => 'transit', 'line' => $seg['line'], 'mode' => $seg['mode'] ?? 'tram'];
                    }
                }

                // Calculate departure: first transit dep - walk time before it
                $firstSeg = collect($trip['segments'])->first();
                $walkBeforeMin = ($firstSeg && ($firstSeg['type'] ?? '') === 'walk') ? ($firstSeg['duration_min'] ?? 0) : 0;
                $depTime = ($firstTransit && ! empty($firstTransit['departure_time']))
                    ? Carbon::parse($firstTransit['departure_time'])->subMinutes($walkBeforeMin)->format('H:i')
                    : ($trip['departure_time'] ?? '');

                // Calculate arrival: last transit arrival + walk time after it
                $lastSeg = collect($trip['segments'])->last();
                $walkAfterMin = ($lastSeg && ($lastSeg['type'] ?? '') === 'walk') ? ($lastSeg['duration_min'] ?? 0) : 0;
                $lastTransit = $transitSegs->last();
                $arrTime = ($lastTransit && ! empty($lastTransit['arrival_time']))
                    ? Carbon::parse($lastTransit['arrival_time'])->addMinutes($walkAfterMin)->format('H:i')
                    : ($trip['arrival_time'] ?? '');

                // Total duration from our calculated dep to arr
                $totalMin = ($depTime && $arrTime)
                    ? max(1, (int) round((Carbon::parse($arrTime)->timestamp - Carbon::parse($depTime)->timestamp) / 60))
                    : ($trip['total_duration_min'] ?? 0);

                $tripOptions[] = [
                    'departure_time' => $depTime,
                    'arrival_time' => $arrTime,
                    'total_duration_min' => $totalMin,
                    'transfers' => $trip['transfers'],
                    'legs' => $legIcons,
                    'walk_min' => $walkMin,
                    'boarding_stop' => $firstTransit['boarding_stop'] ?? '',
                    'first_line_departure' => $firstTransit['departure_time'] ?? '',
                    'segments' => $trip['segments'],
                    'steps' => $trip['steps'],
                ];
            }

            return response()->json([
                'from' => ['name' => $fromName, 'lat' => $fromLat, 'lng' => $fromLng],
                'to' => ['name' => $toName, 'lat' => $toLat, 'lng' => $toLng, 'address' => $toAddress],
                'mode' => 'transit',
                'source' => 'trias',
                'trips' => $tripOptions,
                'geometry' => '',
            ]);
        }

        // Fallback: GTFS-based single-line transit route
        return $this->buildTransitRouteFallback($valhalla, $fromLat, $fromLng, $toLat, $toLng, $fromName, $toName, $toAddress);
    }

    /**
     * Fallback transit route using GTFS static data (walk → single ride → walk).
     */
    private function buildTransitRouteFallback(
        ValhallaRoutingService $valhalla,
        float $fromLat, float $fromLng,
        float $toLat, float $toLng,
        string $fromName, string $toName, ?string $toAddress,
    ): JsonResponse {
        $nearbyService = app(NearbyStopService::class);
        $gtfs = app(GtfsDepartureService::class);

        $originStops = $nearbyService->getWalkableStops($fromLat, $fromLng, 800);
        $destStops = $nearbyService->getWalkableStops($toLat, $toLng, 800);

        if (empty($originStops) || empty($destStops)) {
            return response()->json(['error' => 'No transit stops nearby'], 404);
        }

        $originStop = $originStops[0];
        $destStop = $destStops[0];
        $transitLine = null;
        $transitDirection = null;
        $transitTime = null;
        $routeStops = null;

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

        if (! $transitLine) {
            $departures = $gtfs->getDepartures($originStops[0]['name'], 5);
            $firstDep = $departures['departures'][0] ?? null;
            if ($firstDep) {
                $transitLine = $firstDep['line'];
                $transitDirection = $firstDep['direction'];
                $transitTime = $firstDep['departures'][0] ?? null;
            }
        }

        $walk1 = $valhalla->route($fromLat, $fromLng, $originStop['lat'], $originStop['lng'], 'pedestrian');
        $walk2 = $valhalla->route($destStop['lat'], $destStop['lng'], $toLat, $toLng, 'pedestrian');

        $segments = [];

        if ($walk1 && $walk1['duration_min'] > 0) {
            $segments[] = [
                'type' => 'walk',
                'geometry' => $walk1['geometry'],
                'coordinates' => [],
                'duration_min' => $walk1['duration_min'],
                'distance_km' => $walk1['distance_km'],
            ];
        }

        $transitCoords = [];
        $transitStopNames = [];
        if ($routeStops && ! empty($routeStops['stops'])) {
            foreach ($routeStops['stops'] as $s) {
                $transitCoords[] = [$s['lng'], $s['lat']];
                $transitStopNames[] = $s['name'];
            }
        } else {
            $transitCoords = [[$originStop['lng'], $originStop['lat']], [$destStop['lng'], $destStop['lat']]];
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
                'coordinates' => [],
                'duration_min' => $walk2['duration_min'],
                'distance_km' => $walk2['distance_km'],
            ];
        }

        $totalMin = array_sum(array_column($segments, 'duration_min')) + ($transitTime ?? 0);

        $steps = [];
        foreach ($segments as $seg) {
            if ($seg['type'] === 'walk') {
                $steps[] = ['instruction' => "Walk {$seg['duration_min']} min", 'distance_km' => $seg['distance_km'] ?? 0, 'time_sec' => $seg['duration_min'] * 60, 'type' => 'walk', 'emoji' => '🚶'];
            } else {
                $lineLabel = $seg['line'] !== '?' ? "Line {$seg['line']}" : 'Transit';
                $dirLabel = $seg['direction'] ? " → {$seg['direction']}" : '';
                $steps[] = ['instruction' => "{$lineLabel}{$dirLabel} (".count($seg['stop_names']).' stops)', 'distance_km' => 0, 'time_sec' => $seg['duration_min'] * 60, 'type' => 'ride', 'emoji' => '🚋'];
            }
        }

        return response()->json([
            'from' => ['name' => $fromName, 'lat' => $fromLat, 'lng' => $fromLng],
            'to' => ['name' => $toName, 'lat' => $toLat, 'lng' => $toLng, 'address' => $toAddress],
            'mode' => 'transit',
            'source' => 'gtfs_fallback',
            'duration_min' => $totalMin,
            'distance_km' => round($this->haversineKm($fromLat, $fromLng, $toLat, $toLng), 1),
            'segments' => $segments,
            'steps' => $steps,
            'geometry' => '',
        ]);
    }

    /**
     * Build journey steps from a set of segments (for stitching routes).
     */
    private function buildStepsForSegments(array $segments): array
    {
        $steps = [];
        foreach ($segments as $seg) {
            if (($seg['type'] ?? '') === 'walk') {
                $steps[] = ['instruction' => 'Walk '.($seg['duration_min'] ?? '?').' min', 'distance_km' => $seg['distance_km'] ?? round(($seg['duration_min'] ?? 0) * 0.08, 1), 'time_sec' => ($seg['duration_min'] ?? 0) * 60, 'type' => 'walk', 'emoji' => '🚶'];
            } elseif (($seg['type'] ?? '') === 'transit') {
                $mode = $seg['mode'] ?? 'tram';
                $emoji = $mode === 'rail' ? '🚂' : ($mode === 'tram' ? '🚋' : '🚌');
                $steps[] = ['instruction' => 'Board Line '.($seg['line'] ?? '?').' at '.($seg['boarding_stop'] ?? '?'), 'distance_km' => 0, 'time_sec' => 0, 'type' => 'board', 'emoji' => $emoji, 'detail' => 'Dep '.($seg['departure_time'] ?? '')];
                $steps[] = ['instruction' => 'Ride to '.($seg['alighting_stop'] ?? '?'), 'distance_km' => 0, 'time_sec' => ($seg['duration_min'] ?? 0) * 60, 'type' => 'ride', 'emoji' => $emoji, 'detail' => 'Arr '.($seg['arrival_time'] ?? '')];
            }
        }

        return $steps;
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
