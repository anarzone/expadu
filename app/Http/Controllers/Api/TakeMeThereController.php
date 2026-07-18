<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Gtfs\GtfsStop;
use App\Models\JourneyRecent;
use App\Services\DisruptionService;
use App\Services\UserLocationService;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Journey;
use App\Transit\FareAdvisor;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Take me there" — the only journey-planning surface in v2. Every entity
 * (office, place, event) opens this with a destination; origin comes from the
 * shared resolver (UserLocationService::context — live / confirmed / ping).
 * If no origin is known, the client is asked to obtain one rather than being
 * routed from a guessed home or city centre. The response carries
 * journey-aware Rheinlandtarif fare advice (FareAdvisor) and disruptions
 * filtered to the journey's lines.
 */
class TakeMeThereController extends Controller
{
    /**
     * A user within this many metres of a stop is treated as already standing
     * there, so the plan originates from the stop (~zero access walk) and an
     * imminent departure they could catch isn't dropped as unreachable.
     */
    private const AT_STOP_METRES = 150;

    /**
     * Outer bound for the "tight" surface. Beyond this a soon departure isn't
     * realistically catchable, so the walk-folded plan is simply correct and we
     * don't pay for a second plan.
     */
    private const SPRINT_METRES = 500;

    /** Normal walking pace (~5 km/h), for the access walk we quote the user. */
    private const WALK_M_PER_MIN = 83;

    /** Brisk walk / light jog — the feasibility bound for "could still catch it". */
    private const SPRINT_M_PER_MIN = 110;

    public function __invoke(
        Request $request,
        RouteService $routes,
        FareAdvisor $fareAdvisor,
        DisruptionService $disruptions,
    ): JsonResponse {
        $validated = $request->validate([
            'to_lat' => ['required', 'numeric', 'between:-90,90'],
            'to_lng' => ['required', 'numeric', 'between:-180,180'],
            'to_name' => ['nullable', 'string', 'max:200'],
            'from_lat' => ['nullable', 'required_with:from_lng', 'numeric', 'between:-90,90'],
            'from_lng' => ['nullable', 'required_with:from_lat', 'numeric', 'between:-180,180'],
            'from_name' => ['nullable', 'string', 'max:120'],
            // Plan for later / by a deadline: an ISO time and whether it's the
            // arrival ("arrive by") rather than the departure ("depart at").
            'depart_at' => ['nullable', 'date'],
            'arrive_by' => ['nullable', 'boolean'],
            // "Show more options": also route via the city's interchanges to
            // surface alternative line combinations the Pareto set drops.
            'more' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();

        if (isset($validated['from_lat'], $validated['from_lng'])) {
            // An explicit origin (the Places list's resolved From, or an "I'm
            // here" fix) — keep its label so the sheet headline matches the card.
            $from = new GeoPoint((float) $validated['from_lat'], (float) $validated['from_lng']);
            $fromName = $validated['from_name'] ?? 'Your location';
        } else {
            $locations = app(UserLocationService::class);
            $origin = $locations->context($user, $request);

            if (! $origin->hasOrigin()) {
                return response()->json([
                    'code' => 'location_required',
                    'message' => 'Set your location before planning this journey.',
                ], 422);
            }

            // Same origin the Places list measured from → the sheet headline
            // and the card "min away" agree by construction.
            $from = new GeoPoint((float) $origin->lat, (float) $origin->lng);
            $fromName = $origin->label ?? 'Your location';
        }

        $to = new GeoPoint((float) $validated['to_lat'], (float) $validated['to_lng']);

        // Google-Maps-style recents: remember the destination (and the origin
        // when the user explicitly chose one) for the search-field defaults.
        JourneyRecent::record($user->id, 'destination', (string) ($validated['to_name'] ?? ''), $to->lat, $to->lng);
        if (isset($validated['from_lat'], $validated['from_lng'], $validated['from_name'])) {
            JourneyRecent::record($user->id, 'origin', $validated['from_name'], $from->lat, $from->lng);
        }

        // Ask for a wide Pareto set (arrival × changes × walking) so the list
        // can show the real spread of alternatives — direct-ish with a longer
        // walk vs. an extra change with less — not just the top few. pruneAbsurd
        // still drops night-gap junk; the client sorts/filters the rest.
        $departAt = isset($validated['depart_at'])
            ? CarbonImmutable::parse($validated['depart_at'])
            : null;
        $arriveBy = (bool) ($validated['arrive_by'] ?? false);
        $variety = (bool) ($validated['more'] ?? false);

        // Where the user boards. If they're essentially standing at a stop, plan
        // from the stop itself — the engine otherwise folds in the walk and drops
        // an imminent departure as "unreachable" even though they're right there.
        // The response keeps the real origin so the map + fare still reflect where
        // they are.
        $nearest = $this->nearestStop($from);
        $atStop = $nearest !== null && $nearest['metres'] <= self::AT_STOP_METRES;

        $result = $routes->plan(
            $atStop ? $nearest['point'] : $from,
            $to, $departAt, 10, $arriveBy, $variety,
        );

        $journeys = array_map(fn (Journey $j) => $j->toArray(), $result->journeys);

        // Near a stop but not at it, on a "leave now" search: the walk-folded plan
        // drops departures the user might still make at a jog. Surface the single
        // soonest such departure, flagged "tight", and let them decide.
        if (! $atStop
            && $departAt === null
            && ! $arriveBy
            && $nearest !== null
            && $nearest['metres'] <= self::SPRINT_METRES
        ) {
            $tight = $this->tightOption($routes, $nearest, $to, $result->journeys);

            if ($tight !== null) {
                array_unshift($journeys, $tight);
            }
        }

        // Journey-aware Rheinlandtarif advice for the transit option (walk/bike
        // options are free and carry no ticket). Computed for the first transit
        // journey wherever it sits in the list.
        $transitJourney = collect($result->journeys)
            ->first(fn ($journey) => $journey->mode() === 'transit');
        $ticket = null;
        if ($transitJourney !== null) {
            $ticket = $fareAdvisor->advise(
                $transitJourney,
                $routes->reverseGeocode($from)?->municipality,
                $routes->reverseGeocode($to)?->municipality,
                (bool) ($user->has_deutschlandticket ?? false),
            )->toArray();
        }

        $journeyLines = collect($result->journeys)
            ->flatMap(fn ($journey) => $journey->lines())
            ->unique()
            ->values();

        $relevantDisruptions = collect($disruptions->getLineDisruptions())
            ->filter(fn ($d) => collect($d['affected_lines'] ?? [])
                ->map(fn ($l) => (string) $l)
                ->intersect($journeyLines)
                ->isNotEmpty())
            ->map(fn ($d) => [
                'title' => $d['title'] ?? '',
                'severity' => $d['severity'] ?? 'minor',
                'lines' => $d['affected_lines'] ?? [],
            ])
            ->values()
            ->all();

        return response()->json([
            'source' => $result->source,
            'journeys' => $journeys,
            'degraded' => $result->degraded,
            'from' => ['name' => $fromName, 'lat' => $from->lat, 'lng' => $from->lng],
            'to' => ['name' => $validated['to_name'] ?? '', 'lat' => $to->lat, 'lng' => $to->lng],
            'ticket' => $ticket,
            'disruptions' => $relevantDisruptions,
        ]);
    }

    /**
     * The nearest boardable transit stop to an origin, with its crow-flies
     * distance and name. Ordered by a cheap Manhattan proxy in SQL, then the real
     * haversine on the winner. Null when the feed has no located stops.
     *
     * @return array{point: GeoPoint, metres: float, name: string}|null
     */
    private function nearestStop(GeoPoint $from): ?array
    {
        $stop = GtfsStop::query()
            ->where('location_type', 0)
            ->whereNotNull('stop_lat')
            ->whereNotNull('stop_lng')
            ->selectRaw(
                'stop_name, stop_lat, stop_lng, (ABS(stop_lat - ?) + ABS(stop_lng - ?)) as dist',
                [$from->lat, $from->lng],
            )
            ->orderBy('dist')
            ->first();

        if ($stop === null) {
            return null;
        }

        return [
            'point' => new GeoPoint((float) $stop->stop_lat, (float) $stop->stop_lng),
            'metres' => $this->metresBetween(
                $from->lat, $from->lng, (float) $stop->stop_lat, (float) $stop->stop_lng,
            ),
            'name' => (string) $stop->stop_name,
        ];
    }

    /**
     * The soonest departure from {@see $nearest} that the walk-folded plan drops
     * as unreachable but the user could still catch at a brisk pace — annotated
     * with the access walk so the UI can flag it "tight". A second plan from the
     * stop (zero access walk) surfaces the imminent departures; we keep only one
     * that (a) leaves sooner than anything the safe plan already offers and
     * (b) is reachable at a jog. Null when there's nothing worth the hustle.
     *
     * @param  array{point: GeoPoint, metres: float, name: string}  $nearest
     * @param  list<Journey>  $safeJourneys
     * @return array<string, mixed>|null
     */
    private function tightOption(RouteService $routes, array $nearest, GeoPoint $to, array $safeJourneys): ?array
    {
        $now = CarbonImmutable::now();
        $walkMin = $nearest['metres'] / self::WALK_M_PER_MIN;
        $hustleMin = $nearest['metres'] / self::SPRINT_M_PER_MIN;

        // The soonest transit departure the safe plan already offers — a tight
        // option is only worth showing if it beats this.
        $safeEarliest = null;
        foreach ($safeJourneys as $journey) {
            if ($journey->mode() !== 'transit') {
                continue;
            }
            if ($safeEarliest === null || $journey->departAt->lt($safeEarliest)) {
                $safeEarliest = $journey->departAt;
            }
        }

        // Nothing sooner than the safe plan is catchable even at a jog — skip the
        // extra plan entirely.
        if ($safeEarliest !== null
            && ($safeEarliest->getTimestamp() - $now->getTimestamp()) / 60 <= $hustleMin
        ) {
            return null;
        }

        $stopResult = $routes->plan($nearest['point'], $to, null, 10, false, false);

        $best = null;
        foreach ($stopResult->journeys as $journey) {
            if ($journey->mode() !== 'transit') {
                continue;
            }

            $departsInMin = ($journey->departAt->getTimestamp() - $now->getTimestamp()) / 60;

            if ($departsInMin < $hustleMin) {
                continue; // gone before they could reach the stop, even at a jog
            }
            if ($safeEarliest !== null && ! $journey->departAt->lt($safeEarliest)) {
                continue; // no sooner than an option they can make comfortably
            }
            if ($best === null || $journey->departAt->lt($best->departAt)) {
                $best = $journey;
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            ...$best->toArray(),
            'tight' => true,
            'access_walk_min' => max(1, (int) round($walkMin)),
            'access_stop_name' => $nearest['name'],
        ];
    }

    private function metresBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
