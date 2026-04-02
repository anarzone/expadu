<?php

namespace App\Services;

use App\Models\Gtfs\GtfsStop;
use App\Models\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;

/**
 * Finds nearby transit stops within walking distance and provides
 * departures grouped by transport type (KVB tram/bus vs S-Bahn/RE).
 */
class NearbyStopService
{
    /**
     * Find all KVB stops within walking radius.
     *
     * @return array<int, array{id: int, name: string, lines: string[], lat: float, lng: float, distance_m: int, walk_min: int}>
     */
    public function getWalkableStops(float $lat, float $lng, int $radiusMeters = 500): array
    {
        $kvb = app(KvbApiService::class);
        $allStops = $kvb->getStops();

        $nearby = [];
        $seenNames = [];

        foreach ($allStops as $stop) {
            if (! $stop['lat'] || ! $stop['lng']) {
                continue;
            }

            $dist = $this->haversineMeters($lat, $lng, $stop['lat'], $stop['lng']);
            if ($dist > $radiusMeters) {
                continue;
            }

            // Deduplicate by name (same stop can have multiple platforms)
            $key = $stop['name'];
            if (isset($seenNames[$key])) {
                // Merge lines from duplicate stop entries
                $seenNames[$key]['lines'] = array_values(array_unique(
                    array_merge($seenNames[$key]['lines'], $stop['lines'])
                ));

                continue;
            }

            $walkMin = max(1, (int) round($dist / 80)); // ~80m/min walking speed

            $seenNames[$key] = [
                'id' => $stop['id'],
                'name' => $stop['name'],
                'lines' => $stop['lines'],
                'lat' => $stop['lat'],
                'lng' => $stop['lng'],
                'distance_m' => (int) round($dist),
                'walk_min' => $walkMin,
            ];
        }

        // Sort by distance
        $result = array_values($seenNames);
        usort($result, fn ($a, $b) => $a['distance_m'] <=> $b['distance_m']);

        return $result;
    }

    /**
     * Find nearby GTFS rail stations (S-Bahn, RE, RB) within walking radius.
     *
     * @return array<int, array{name: string, lat: float, lng: float, distance_m: int, walk_min: int}>
     */
    public function getNearbyRailStations(float $lat, float $lng, int $radiusMeters = 5000): array
    {
        return Cache::remember("rail_stations_{$lat}_{$lng}_{$radiusMeters}", 300, function () use ($lat, $lng, $radiusMeters) {
            // Find GTFS stops that serve rail routes (route_type=2)
            // Use a generous radius — rail stations are spaced further apart than tram stops
            $stations = GtfsStop::query()
                ->whereNotNull('stop_lat')
                ->whereNotNull('stop_lng')
                ->where('location_type', 0)
                ->whereIn('stop_id', function ($q) {
                    $q->select('st.stop_id')
                        ->from('gtfs_stop_times as st')
                        ->join('gtfs_trips as t', 't.trip_id', '=', 'st.trip_id')
                        ->join('gtfs_routes as r', 'r.route_id', '=', 't.route_id')
                        ->where('r.route_type', 2)
                        ->distinct();
                })
                ->get();

            $seenNames = [];

            foreach ($stations as $stop) {
                $dist = $this->haversineMeters($lat, $lng, (float) $stop->stop_lat, (float) $stop->stop_lng);
                if ($dist > $radiusMeters) {
                    continue;
                }

                $key = $stop->stop_name;
                if (isset($seenNames[$key]) && $seenNames[$key]['distance_m'] <= (int) round($dist)) {
                    continue;
                }

                $seenNames[$key] = [
                    'name' => $stop->stop_name,
                    'lat' => (float) $stop->stop_lat,
                    'lng' => (float) $stop->stop_lng,
                    'distance_m' => (int) round($dist),
                    'walk_min' => max(1, (int) round($dist / 80)),
                ];
            }

            $result = array_values($seenNames);
            usort($result, fn ($a, $b) => $a['distance_m'] <=> $b['distance_m']);

            return array_slice($result, 0, 3);
        });
    }

    /**
     * Get departures from nearby stops, split into KVB (tram/bus) and DB (S-Bahn/RE).
     * Optionally highlights lines heading towards a destination.
     *
     * @return array{kvb: array, db: array, stops_used: array}
     */
    public function getDeparturesByType(
        float $lat,
        float $lng,
        ?float $destLat = null,
        ?float $destLng = null,
        ?string $destName = null,
    ): array {
        $stops = $this->getWalkableStops($lat, $lng, 600);

        $gtfs = App::make(GtfsDepartureService::class);
        $disruptedLines = app(DisruptionService::class)->getDisruptedLines();

        // Find destination stop for "towards" highlighting
        $destLines = [];
        if ($destLat && $destLng) {
            $kvb = app(KvbApiService::class);
            $destStop = $kvb->nearestStop($destLat, $destLng);
            $destLines = $destStop['lines'] ?? [];
        }

        $kvbDeps = [];
        $dbDeps = [];
        $stopsUsed = [];
        $seenLineDir = [];

        // Query departures from top 3 nearest KVB stops for tram/bus
        foreach (array_slice($stops, 0, 3) as $stop) {
            $result = $gtfs->getDepartures($stop['name'], 15);

            if (empty($result['departures'])) {
                continue;
            }

            $stopsUsed[] = [
                'name' => $stop['name'],
                'walk_min' => $stop['walk_min'],
                'distance_m' => $stop['distance_m'],
            ];

            foreach ($result['departures'] as $dep) {
                $key = $dep['line'].'_'.$dep['direction'];
                if (isset($seenLineDir[$key])) {
                    continue;
                }
                $seenLineDir[$key] = true;

                $line = $dep['line'];
                $isDisrupted = isset($disruptedLines[$line]);
                $towardsDest = ! empty($destLines) && in_array($line, $destLines, true);

                $entry = [
                    'line' => $line,
                    'direction' => $dep['direction'],
                    'color' => $dep['color'] ?? '#1A4CD4',
                    'type' => $dep['type'],
                    'departures' => $dep['departures'],
                    'delay' => $dep['delay'] ?? 0,
                    'cancelled' => $dep['cancelled'] ?? false,
                    'stop_name' => $stop['name'],
                    'walk_min' => $stop['walk_min'],
                    'disrupted' => $isDisrupted,
                    'disruption_severity' => $isDisrupted ? $disruptedLines[$line] : null,
                    'towards_dest' => $towardsDest,
                    'dest_name' => $towardsDest ? $destName : null,
                ];

                if (in_array($dep['type'], ['rail', 'subway'], true) || preg_match('/^(S|RE|RB|IC)/', $line)) {
                    $dbDeps[] = $entry;
                } else {
                    $kvbDeps[] = $entry;
                }
            }
        }

        // Query departures from nearby GTFS rail stations (S-Bahn / RE / RB)
        $railStations = $this->getNearbyRailStations($lat, $lng);

        foreach ($railStations as $station) {
            $result = $gtfs->getDepartures($station['name'], 15);

            if (empty($result['departures'])) {
                continue;
            }

            $stopsUsed[] = [
                'name' => $station['name'],
                'walk_min' => $station['walk_min'],
                'distance_m' => $station['distance_m'],
            ];

            foreach ($result['departures'] as $dep) {
                $line = $dep['line'];
                // Only include rail services from these stations
                if (! in_array($dep['type'], ['rail', 'subway'], true) && ! preg_match('/^(S|RE|RB|IC)/', $line)) {
                    continue;
                }

                $key = $dep['line'].'_'.$dep['direction'];
                if (isset($seenLineDir[$key])) {
                    continue;
                }
                $seenLineDir[$key] = true;

                $isDisrupted = isset($disruptedLines[$line]);

                $dbDeps[] = [
                    'line' => $line,
                    'direction' => $dep['direction'],
                    'color' => $dep['color'] ?? '#C4271A',
                    'type' => $dep['type'],
                    'departures' => $dep['departures'],
                    'delay' => $dep['delay'] ?? 0,
                    'cancelled' => $dep['cancelled'] ?? false,
                    'stop_name' => $station['name'],
                    'walk_min' => $station['walk_min'],
                    'disrupted' => $isDisrupted,
                    'disruption_severity' => $isDisrupted ? $disruptedLines[$line] : null,
                    'towards_dest' => false,
                    'dest_name' => null,
                ];
            }
        }

        // Sort: towards-destination first, then trams before buses, then by soonest departure
        $typeOrder = ['tram' => 0, 'subway' => 1, 'rail' => 2, 'bus' => 3];
        $sorter = function ($a, $b) use ($typeOrder) {
            if ($a['towards_dest'] !== $b['towards_dest']) {
                return $b['towards_dest'] <=> $a['towards_dest'];
            }
            $aType = $typeOrder[$a['type'] ?? 'bus'] ?? 3;
            $bType = $typeOrder[$b['type'] ?? 'bus'] ?? 3;
            if ($aType !== $bType) {
                return $aType <=> $bType;
            }

            return ($a['departures'][0] ?? 999) <=> ($b['departures'][0] ?? 999);
        };

        usort($kvbDeps, $sorter);
        usort($dbDeps, $sorter);

        return [
            'kvb' => array_slice($kvbDeps, 0, 8),
            'db' => array_slice($dbDeps, 0, 6),
            'stops_used' => $stopsUsed,
        ];
    }

    /**
     * Determine where the user is likely heading based on time + location context.
     *
     * @return array{lat: float, lng: float, name: string}|null
     */
    public function predictDestination(User $user, float $currentLat, float $currentLng): ?array
    {
        $home = $user->places()->where('category', 'home')->first();
        $work = $user->places()->where('category', 'work')->first();
        $homeLat = $home?->lat ? (float) $home->lat : null;
        $homeLng = $home?->lng ? (float) $home->lng : null;
        $workLat = $work?->lat ? (float) $work->lat : null;
        $workLng = $work?->lng ? (float) $work->lng : null;

        $hour = now()->hour;
        $isWeekday = now()->isWeekday();

        // Check if user is near Home or Work
        $nearHome = $homeLat && $this->haversineMeters($currentLat, $currentLng, $homeLat, $homeLng) < 500;
        $nearWork = $workLat && $this->haversineMeters($currentLat, $currentLng, $workLat, $workLng) < 500;

        // Near Home → going to Work (weekday mornings)
        if ($nearHome && $isWeekday && $hour >= 6 && $hour < 10 && $workLat) {
            return ['lat' => $workLat, 'lng' => $workLng, 'name' => $work->name ?? 'Work'];
        }

        // Near Work → going Home
        if ($nearWork && $homeLat) {
            return ['lat' => $homeLat, 'lng' => $homeLng, 'name' => $home->name ?? 'Home'];
        }

        // Somewhere else → going Home (default assumption after 30+ min dwell)
        if ($homeLat && ! $nearHome) {
            return ['lat' => $homeLat, 'lng' => $homeLng, 'name' => $home->name ?? 'Home'];
        }

        return null;
    }

    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
