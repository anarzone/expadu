<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DisruptionService;
use App\Services\GtfsDepartureService;
use App\Services\KvbApiService;
use App\Services\LocationPatternService;
use App\Services\NearbyStopService;
use App\Services\RecommendationService;
use App\Services\UserLocationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransitController extends Controller
{
    public function index(Request $request, GtfsDepartureService $gtfsService, RecommendationService $recommendationService, LocationPatternService $patternService, NearbyStopService $nearbyService): Response
    {
        $user = $request->user();
        $location = app(UserLocationService::class)->resolve($user, $request);
        $stop = $request->query('stop');

        // Use resolved location for departures, or manual stop override
        $nearbyLat = $location['lat'];
        $nearbyLng = $location['lng'];

        if ($stop) {
            $gtfsDepartures = $gtfsService->getDepartures($stop, 20);
        } else {
            $gtfsDepartures = $gtfsService->getDeparturesNearby($nearbyLat, $nearbyLng, 20);
        }

        // Enrich routines with next GTFS departure
        $routines = $user->routines()->get()->map(function ($r) use ($gtfsService) {
            $data = [
                'id' => $r->id,
                'emoji' => $r->emoji,
                'name' => $r->name,
                'from_stop' => $r->from_stop,
                'to_stop' => $r->to_stop,
                'days' => $r->days,
                'departure_time' => $r->departure_time,
                'is_active' => $r->is_active,
            ];

            // Add next live departure from the routine's origin stop
            $deps = $gtfsService->getDepartures($r->from_stop, 3);
            $nextDep = collect($deps['departures'] ?? [])->first();
            $data['next_departure_min'] = $nextDep['departures'][0] ?? null;
            $data['next_departure_line'] = $nextDep['line'] ?? null;

            return $data;
        });

        return Inertia::render('transit', [
            'routines' => $routines,
            'detectedRoutines' => $patternService->detectUnsavedRoutines($user),
            'currentStop' => $gtfsDepartures['stop_name'] ?? $stop,
            'userPlaces' => $user->places()
                ->select('id', 'emoji', 'name', 'address', 'lat', 'lng')
                ->get(),
            'gtfsDepartures' => $gtfsDepartures,
            'gtfsStops' => fn () => $stop ? $gtfsService->searchStops($stop, 20) : [],
            'commuteRecommendation' => $recommendationService->getCommuteRecommendation($user),
            'userLocation' => ['name' => $location['name'], 'address' => $location['name'], 'lat' => $nearbyLat, 'lng' => $nearbyLng],
            'disruptions' => $this->buildDisruptionCards($user, $nearbyLat, $nearbyLng),
            'nearbyDepartures' => fn () => $this->buildNearbyDepartures($user, $nearbyService, $nearbyLat, $nearbyLng),
        ]);
    }

    /**
     * Build nearby departures split by KVB (tram/bus) and DB (S-Bahn/RE).
     *
     * @return array{kvb: array, db: array, stops_used: array}
     */
    private function buildNearbyDepartures(User $user, NearbyStopService $nearbyService, float $lat, float $lng): array
    {
        // Predict where user is heading based on time + location
        $dest = $nearbyService->predictDestination($user, $lat, $lng);

        return $nearbyService->getDeparturesByType(
            $lat,
            $lng,
            $dest['lat'] ?? null,
            $dest['lng'] ?? null,
            $dest['name'] ?? null,
        );
    }

    /**
     * Build personalized disruption cards — check if user's lines are affected.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildDisruptionCards(User $user, float $homeLat, float $homeLng): array
    {
        $disruptions = app(DisruptionService::class)->getLineDisruptions();

        if (empty($disruptions)) {
            return [];
        }

        // Find which lines serve the user's Home and Work stops
        $kvb = app(KvbApiService::class);
        $homeStop = $kvb->nearestStop($homeLat, $homeLng);
        $work = $user->places()->where('category', 'work')->first();
        $workStop = $work?->lat ? $kvb->nearestStop((float) $work->lat, (float) $work->lng) : null;

        $userLines = array_unique(array_merge(
            $homeStop['lines'] ?? [],
            $workStop['lines'] ?? [],
        ));

        // Enrich each disruption with personalization
        $cards = [];
        foreach ($disruptions as $d) {
            $affectedLines = $d['affected_lines'] ?? [];
            $overlap = array_values(array_intersect($affectedLines, $userLines));
            $isPersonal = ! empty($overlap);

            $cards[] = [
                'id' => $d['id'],
                'title' => $d['title'],
                'description' => $d['description'] ?? '',
                'severity' => $d['severity'] ?? 'minor',
                'type' => $d['type'] ?? 'line',
                'affected_lines' => $affectedLines,
                'is_personal' => $isPersonal,
                'affected_user_lines' => $overlap,
            ];
        }

        // Sort: personal first, then tram lines (1-18), then severity
        usort($cards, function ($a, $b) {
            // Personal disruptions always first
            if ($a['is_personal'] !== $b['is_personal']) {
                return $b['is_personal'] <=> $a['is_personal'];
            }

            // Tram lines (1-18) before bus/other
            $aHasTram = ! empty(array_filter($a['affected_lines'], fn ($l) => is_numeric($l) && (int) $l <= 18));
            $bHasTram = ! empty(array_filter($b['affected_lines'], fn ($l) => is_numeric($l) && (int) $l <= 18));
            if ($aHasTram !== $bHasTram) {
                return $bHasTram <=> $aHasTram;
            }

            // Then by severity
            $severityOrder = ['critical' => 0, 'major' => 1, 'minor' => 2];

            return ($severityOrder[$a['severity']] ?? 3) <=> ($severityOrder[$b['severity']] ?? 3);
        });

        return array_slice($cards, 0, 5);
    }
}
