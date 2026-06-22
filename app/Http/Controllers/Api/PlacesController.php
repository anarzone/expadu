<?php

namespace App\Http\Controllers\Api;

use App\Enums\SpotCategory;
use App\Enums\SpotFeedbackState;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlaceResource;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Services\UserLocationService;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/places?veedel=&category=&page=
 *
 * Lists physical-leisure places from our own seeded DB — no live
 * third-party calls. Distance is measured from the shared origin
 * (UserLocationService::context — live fix / picked or browsed area, else
 * none); transit_hint is the nearest GTFS stop (our static data).
 */
class PlacesController extends Controller
{
    public function __construct(
        private readonly UserLocationService $locations,
        private readonly RouteService $routes,
    ) {}

    private const PER_PAGE = 20;

    private const COARSE = ['park', 'pitch', 'court', 'swimming', 'playground', 'dog_park', 'culture'];

    /**
     * Synthesised names from the OSM import — commodity facilities, not
     * destinations. They rank below named venues in the list.
     */
    private const GENERIC_NAME_REGEX = '^(Spielplatz|Bolzplatz|Basketballplatz|Tennisplatz|Tischtennisplatte|Boulebahn|Skatepark|Hundewiese|Grillplatz)';

    /**
     * One place with the full card contract — used when the detail swaps
     * to a nearby place without going through the list.
     */
    public function show(Request $request, Spot $spot): PlaceResource
    {
        $origin = $this->locations->context($request->user(), $request);

        $spot->distance_km = $origin->hasOrigin()
            ? $this->haversineKm($origin->lat, $origin->lng, (float) $spot->lat, (float) $spot->lng)
            : null;
        $spot->transit_hint = $this->nearestStopHint((float) $spot->lat, (float) $spot->lng);
        $spot->activities = $this->activitiesForParks(collect([$spot]))[$spot->name] ?? [];
        $spot->cluster_size = Spot::query()
            ->where('name', $spot->name)
            ->where('veedel', $spot->veedel)
            ->whereRaw('round(lat::numeric, 3) = round(?::numeric, 3) and round(lng::numeric, 3) = round(?::numeric, 3)', [$spot->lat, $spot->lng])
            ->count();

        // The user's standing feedback, so the detail modal (opened from the
        // home rails or Places) reflects it.
        $row = SpotFeedback::query()
            ->where('user_id', $request->user()->id)
            ->where('spot_id', $spot->id)
            ->first();
        $spot->feedback_state = $row?->state?->value;
        $spot->feedback_rating = $row?->rating;
        $spot->travel_min = $origin->hasOrigin()
            ? ($this->routes->travelMatrix(
                $origin->toGeoPoint(),
                [new GeoPoint((float) $spot->lat, (float) $spot->lng)],
            )[0] ?? null)
            : null;

        return new PlaceResource($spot);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'veedel' => ['nullable', 'string', 'max:100'],
            'bezirk' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'in:'.implode(',', self::COARSE)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        // The browsed Veedel is the fallback origin when we have no live fix —
        // distances reflect the area you're looking at, never a guessed centre.
        $browsedVeedel = (! empty($validated['veedel']) && $validated['veedel'] !== 'all')
            ? $validated['veedel']
            : null;
        $origin = $this->locations->context($request->user(), $request, $browsedVeedel);

        // The user's place feedback: "not interested" drops out of the list
        // entirely; the rest carry their state through to a card badge.
        $feedback = SpotFeedback::query()
            ->where('user_id', $request->user()->id)
            ->get(['spot_id', 'state', 'rating'])
            ->keyBy('spot_id');
        $notInterestedIds = $feedback
            ->filter(fn (SpotFeedback $row) => $row->state === SpotFeedbackState::NotInterested)
            ->keys()
            ->all();

        $query = Spot::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->whereIn('category', SpotCategory::placesFines())
            ->when($notInterestedIds !== [], fn ($q) => $q->whereNotIn('id', $notInterestedIds));

        // Venue-first: facilities inside a park collapse into the park's
        // card (its activity chips), so only the park itself and
        // standalone places are list entries.
        if (! empty($validated['category']) && $validated['category'] !== 'park') {
            $fines = SpotCategory::finesForCoarse($validated['category']);

            // An activity filter matches standalone facilities of that
            // kind AND the parks that contain such a facility.
            $query->where(function ($q) use ($fines) {
                $q->where(fn ($standalone) => $standalone
                    ->whereIn('category', $fines)
                    ->whereNull('park_name'))
                    ->orWhere(fn ($venue) => $venue
                        ->where('category', 'park')
                        ->whereIn('name', Spot::query()
                            ->select('park_name')
                            ->whereIn('category', $fines)
                            ->whereNotNull('park_name')));
            });
        } elseif (! empty($validated['category'])) {
            $query->whereIn('category', SpotCategory::finesForCoarse('park'))
                ->whereNull('park_name');
        } else {
            $query->whereNull('park_name');
        }

        $nearbyIncluded = false;
        $selectedVeedel = null;
        if (! empty($validated['veedel']) && $validated['veedel'] !== 'all') {
            $veedel = $selectedVeedel = $validated['veedel'];
            $centroid = DB::table('veedels')
                ->where('name', $veedel)
                ->whereNotNull('centroid_lat')
                ->first(['centroid_lat', 'centroid_lng']);

            if ($centroid) {
                // "{Veedel} & nearby" — the Veedel itself plus anything
                // within ~2 km of its centroid, so the line in the UI is true.
                $nearbyIncluded = true;
                $query->where(function ($q) use ($veedel, $centroid) {
                    $q->where('veedel', $veedel)
                        ->orWhereRaw(
                            '(6371 * acos(LEAST(1, cos(radians(?)) * cos(radians(lat)) * cos(radians(lng) - radians(?)) + sin(radians(?)) * sin(radians(lat))))) <= 2',
                            [$centroid->centroid_lat, $centroid->centroid_lng, $centroid->centroid_lat],
                        );
                });
            } else {
                $query->where('veedel', $veedel);
            }
        } elseif (! empty($validated['bezirk']) && $validated['bezirk'] !== 'all') {
            // Bezirk level: every Stadtteil of the district, no nearby ring.
            $query->whereIn('veedel', DB::table('veedels')
                ->where('bezirk', $validated['bezirk'])
                ->pluck('name'));
        }

        $page = (int) ($validated['page'] ?? 1);

        // OSM seeds many identically-named rows (e.g. three "Tischtennisplatte"
        // in one park corner). Collapse same-name clusters within a ~100m grid
        // cell into one card carrying cluster_size; distinct locations further
        // apart stay separate.
        $query->select('*');

        if ($origin->hasOrigin()) {
            $query->selectRaw(
                '(6371 * acos(LEAST(1, cos(radians(?)) * cos(radians(lat)) * cos(radians(lng) - radians(?)) + sin(radians(?)) * sin(radians(lat))))) as distance_km',
                [$origin->lat, $origin->lng, $origin->lat],
            );
        } else {
            $query->selectRaw('NULL::float8 as distance_km');
        }

        $query
            ->selectRaw('count(*) over (partition by name, veedel, round(lat::numeric, 3), round(lng::numeric, 3)) as cluster_size')
            ->selectRaw('row_number() over (partition by name, veedel, round(lat::numeric, 3), round(lng::numeric, 3) order by id) as cluster_rank');

        $outer = Spot::query()->fromSub($query, 'spots')->where('cluster_rank', 1);

        // The selected Veedel's own places come first — a page of "nearby"
        // results above them would read as broken filtering. Within each
        // group, named destinations beat commodity facilities, then
        // closest to the user wins.
        if ($selectedVeedel !== null) {
            // IS NOT DISTINCT FROM: a plain equals yields NULL for
            // NULL-veedel rows, which DESC sorts FIRST in Postgres —
            // floating unassigned spots above the selected Veedel's own.
            $outer->orderByRaw('(veedel IS NOT DISTINCT FROM ?) desc', [$selectedVeedel]);
        }

        $outer->orderByRaw("(name !~ '".self::GENERIC_NAME_REGEX."') desc");

        // Closest-first only when we actually have an origin; otherwise a
        // stable id order — no fake distance to sort by.
        if ($origin->hasOrigin()) {
            $outer->orderBy('distance_km');
        }

        $paginator = $outer
            ->orderBy('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $page);

        $activities = $this->activitiesForParks($paginator->getCollection());
        $stopHints = $this->stopHintsForPage($paginator->getCollection());

        $paginator->getCollection()->transform(function (Spot $spot) use ($activities, $stopHints, $feedback) {
            $spot->transit_hint = $stopHints[$spot->id] ?? null;
            $spot->activities = $activities[$spot->name] ?? [];
            $row = $feedback->get($spot->id);
            $spot->feedback_state = $row?->state?->value;
            $spot->feedback_rating = $row?->rating;

            return $spot;
        });

        if ($origin->hasOrigin()) {
            $this->attachTravelMinutes($paginator->getCollection(), $origin->lat, $origin->lng);
        }

        return PlaceResource::collection($paginator)
            ->additional(['nearby_included' => $nearbyIncluded]);
    }

    /**
     * What you can do in each park on this page — the distinct facility
     * kinds inside it, as ready-to-render chips.
     *
     * @param  Collection<int, Spot>  $places
     * @return array<string, list<array{emoji: string, label: string}>>
     */
    private function activitiesForParks($places): array
    {
        $parkNames = $places
            ->filter(fn (Spot $spot) => $spot->getRawOriginal('category') === 'park')
            ->pluck('name')
            ->all();

        if ($parkNames === []) {
            return [];
        }

        return DB::table('spots')
            ->whereIn('park_name', $parkNames)
            ->whereIn('category', SpotCategory::placesFines())
            ->distinct()
            ->get(['park_name', 'category'])
            ->groupBy('park_name')
            ->map(fn ($group) => $group
                ->map(fn ($row) => SpotCategory::tryFrom($row->category))
                ->filter()
                ->unique()
                ->sortBy(fn (SpotCategory $category) => $category->value)
                ->map(fn (SpotCategory $category) => [
                    'emoji' => $category->emoji(),
                    'label' => $category->label(),
                ])
                ->values()
                ->all())
            ->all();
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return 6371 * acos(min(1, cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($lng2) - deg2rad($lng1)) + sin(deg2rad($lat1)) * sin(deg2rad($lat2))));
    }

    /**
     * Replace the haversine estimate with real one-to-many street travel
     * minutes (bike) from the anchor. Best-effort and order-preserving: any
     * destination the engine can't reach stays null, and PlaceResource falls
     * back to the heuristic for it.
     *
     * @param  Collection<int, Spot>  $spots
     */
    private function attachTravelMinutes($spots, float $anchorLat, float $anchorLng): void
    {
        if ($spots->isEmpty()) {
            return;
        }

        $list = $spots->values();
        $minutes = $this->routes->travelMatrix(
            new GeoPoint($anchorLat, $anchorLng),
            $list->map(fn (Spot $spot) => new GeoPoint((float) $spot->lat, (float) $spot->lng))->all(),
            'BIKE',
        );

        foreach ($list as $i => $spot) {
            $spot->travel_min = $minutes[$i] ?? null;
        }
    }

    /**
     * Nearest-stop hints for a whole page in ONE query (lateral join) —
     * the per-row version was 20 sequential queries per request.
     *
     * @param  Collection<int, Spot>  $places
     * @return array<int, string>
     */
    private function stopHintsForPage($places): array
    {
        if ($places->isEmpty()) {
            return [];
        }

        try {
            $values = $places
                ->map(fn (Spot $spot) => sprintf('(%d, %.7F, %.7F)', $spot->id, $spot->lat, $spot->lng))
                ->implode(', ');

            $rows = DB::select(
                "SELECT p.id, s.stop_name, s.meters
                 FROM (VALUES {$values}) AS p(id, lat, lng)
                 CROSS JOIN LATERAL (
                     SELECT stop_name,
                            (6371000 * acos(LEAST(1, cos(radians(p.lat)) * cos(radians(stop_lat)) * cos(radians(stop_lng) - radians(p.lng)) + sin(radians(p.lat)) * sin(radians(stop_lat))))) AS meters
                     FROM gtfs_stops
                     WHERE location_type = 0
                       AND stop_lat BETWEEN p.lat - 0.0075 AND p.lat + 0.0075
                       AND stop_lng BETWEEN p.lng - 0.0075 AND p.lng + 0.0075
                     ORDER BY meters
                     LIMIT 1
                 ) s",
            );

            $hints = [];
            foreach ($rows as $row) {
                $walkMin = max(1, (int) round(($row->meters / 4.5 / 1000) * 60));
                $hints[(int) $row->id] = "Nearest stop: {$row->stop_name} (~{$walkMin} min walk)";
            }

            return $hints;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Nearest GTFS stop within ~800m, as a plain hint string.
     */
    private function nearestStopHint(float $lat, float $lng): ?string
    {
        try {
            $delta = 0.0075; // ~800m latitude box

            $stop = DB::table('gtfs_stops')
                ->where('location_type', 0)
                ->whereBetween('stop_lat', [$lat - $delta, $lat + $delta])
                ->whereBetween('stop_lng', [$lng - $delta, $lng + $delta])
                ->selectRaw(
                    'stop_name, (6371000 * acos(LEAST(1, cos(radians(?)) * cos(radians(stop_lat)) * cos(radians(stop_lng) - radians(?)) + sin(radians(?)) * sin(radians(stop_lat))))) as meters',
                    [$lat, $lng, $lat],
                )
                ->orderBy('meters')
                ->first();

            if (! $stop) {
                return null;
            }

            $walkMin = max(1, (int) round(($stop->meters / 4.5 / 1000) * 60));

            return "Nearest stop: {$stop->stop_name} (~{$walkMin} min walk)";
        } catch (\Throwable) {
            return null;
        }
    }
}
