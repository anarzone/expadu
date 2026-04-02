<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DisruptionService;
use App\Services\GtfsDepartureService;
use App\Services\KvbApiService;
use App\Services\LocationPatternService;
use App\Services\NearbyStopService;
use App\Services\RecommendationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransitController extends Controller
{
    public function index(Request $request, GtfsDepartureService $gtfsService, RecommendationService $recommendationService, LocationPatternService $patternService, NearbyStopService $nearbyService): Response
    {
        $user = $request->user();
        $homePlace = $user->places()->orderBy('sort_order')->first();
        $homeLat = $homePlace?->lat ? (float) $homePlace->lat : null;
        $homeLng = $homePlace?->lng ? (float) $homePlace->lng : null;
        $defaultStop = $homePlace?->address ?? 'Ehrenfeld';
        $stop = $request->query('stop', $defaultStop);

        // Coordinates: use GPS (lat/lng params) first, then Home fallback
        $nearbyLat = $request->has('lat') ? (float) $request->query('lat') : $homeLat;
        $nearbyLng = $request->has('lng') ? (float) $request->query('lng') : $homeLng;

        // Main departure board: GPS coords → nearest stop, else Home coords, else text search
        if ($request->has('lat') && $request->has('lng')) {
            $gtfsDepartures = $gtfsService->getDeparturesNearby((float) $request->query('lat'), (float) $request->query('lng'), 20);
        } elseif ($homeLat && $homeLng && ! $request->has('stop')) {
            $gtfsDepartures = $gtfsService->getDeparturesNearby($homeLat, $homeLng, 20);
        } else {
            $gtfsDepartures = $gtfsService->getDepartures($stop, 20);
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
            'gtfsStops' => fn () => $gtfsService->searchStops($stop, 20),
            'commuteRecommendation' => $recommendationService->getCommuteRecommendation($user),
            'disruptions' => $this->buildDisruptionCards($user, $homeLat ?? 50.9375, $homeLng ?? 6.9603),
            'nearbyDepartures' => fn () => $this->buildNearbyDepartures($user, $nearbyService, $nearbyLat ?? 50.9375, $nearbyLng ?? 6.9603),
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

        // Sort: critical first, then personal, then major, then minor
        usort($cards, function ($a, $b) {
            $severityOrder = ['critical' => 0, 'major' => 1, 'minor' => 2];
            $aScore = ($severityOrder[$a['severity']] ?? 3) - ($a['is_personal'] ? 10 : 0);
            $bScore = ($severityOrder[$b['severity']] ?? 3) - ($b['is_personal'] ? 10 : 0);

            return $aScore <=> $bScore;
        });

        return array_slice($cards, 0, 5);
    }
}
