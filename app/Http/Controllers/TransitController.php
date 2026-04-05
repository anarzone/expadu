<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\User;
use App\Services\DisruptionService;
use App\Services\GtfsDepartureService;
use App\Services\KvbApiService;
use App\Services\LocationPatternService;
use App\Services\NearbyStopService;
use App\Services\RecommendationService;
use App\Services\RhineService;
use App\Services\UserLocationService;
use App\Services\WeatherService;
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
        $errors = [];

        // Use resolved location for departures, or manual stop override
        $nearbyLat = $location['lat'];
        $nearbyLng = $location['lng'];

        try {
            if ($stop) {
                $gtfsDepartures = $gtfsService->getDepartures($stop, 20);
            } else {
                $gtfsDepartures = $gtfsService->getDeparturesNearby($nearbyLat, $nearbyLng, 20);
            }
        } catch (\Throwable $e) {
            $gtfsDepartures = ['stop_name' => $stop ?? 'Nearby', 'source' => 'unavailable', 'departures' => []];
            $errors[] = 'Departure data temporarily unavailable';
        }

        if (! empty($errors)) {
            session()->flash('serviceErrors', $errors);
        }

        GtfsDepartureService::logDepartureDebug($user->id, 'transit', $gtfsDepartures, $nearbyLat, $nearbyLng);

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
            // Right panel data (lazy — only evaluated if right panel renders)
            'weather' => fn () => app(WeatherService::class)->getCurrentWeather($nearbyLat, $nearbyLng),
            'forecast' => fn () => app(WeatherService::class)->getForecast($nearbyLat, $nearbyLng),
            'rhineLevel' => fn () => app(RhineService::class)->getCurrentLevel(),
            'todayEvents' => fn () => $this->buildTodayEvents(),
            'activeDisruptions' => fn () => $this->buildActiveDisruptions(),
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

    /** @return array<int, array<string, mixed>> */
    private function buildTodayEvents(): array
    {
        return Event::query()
            ->whereDate('starts_at', today())
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->limit(7)
            ->get(['id', 'title', 'starts_at', 'location_name', 'is_free', 'category'])
            ->map(fn ($e) => [
                'time' => $e->starts_at->format('H:i'),
                'emoji' => match ($e->category) {
                    'music' => '🎵', 'sports' => '⚽', 'language' => '🗣️', 'culture' => '🎭', 'community' => '🤝', 'food' => '🍽️', 'market' => '🛒', default => '📅'
                },
                'title' => $e->title,
                'location' => $e->location_name,
                'badge' => $e->is_free ? 'Free' : ucfirst($e->category ?? 'Event'),
                'badgeType' => $e->is_free ? 'free' : 'category',
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function buildActiveDisruptions(): array
    {
        return collect(app(DisruptionService::class)->getLineDisruptions())
            ->map(fn ($d) => ['title' => $d['title'], 'severity' => $d['severity'], 'lines' => $d['affected_lines']])
            ->take(5)
            ->values()
            ->all();
    }
}
