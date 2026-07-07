<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Gtfs\GtfsStop;
use App\Models\JourneyRecent;
use App\Services\DisruptionService;
use App\Services\UserLocationService;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\FareAdvisor;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Take me there" — the only journey-planning surface in v2. Every entity
 * (office, place, event) opens this with a destination; origin comes from the
 * shared resolver (UserLocationService::context — live / confirmed / ping),
 * with a last-resort fallback when nothing is known. The response carries
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
            'from_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'from_lng' => ['nullable', 'numeric', 'between:-180,180'],
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

            if ($origin->hasOrigin()) {
                // Same origin the Places list measured from → the sheet headline
                // and the card "min away" agree by construction.
                $from = new GeoPoint((float) $origin->lat, (float) $origin->lng);
                $fromName = $origin->label ?? 'Your location';
            } else {
                // Nothing known and no explicit origin passed — last resort so
                // the journey still renders. The From control (later slice)
                // makes this path rare by always passing an origin.
                $resolved = $locations->resolve($user, $request);
                $from = new GeoPoint((float) $resolved['lat'], (float) $resolved['lng']);
                $fromName = (string) ($resolved['name'] ?? 'Your location');
            }
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

        // If the user is essentially standing at a stop, plan from the stop
        // itself. The routing engine otherwise folds in the walk to the stop and
        // drops an imminent departure as "unreachable" — but they can catch it
        // if they're already there. Show it; let them decide. The response keeps
        // the real origin so the map + fare still reflect where they are.
        $planFrom = $this->atStopOrigin($from) ?? $from;

        $result = $routes->plan($planFrom, $to, $departAt, 10, $arriveBy, $variety);

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
            ...$result->toArray(),
            'from' => ['name' => $fromName, 'lat' => $from->lat, 'lng' => $from->lng],
            'to' => ['name' => $validated['to_name'] ?? '', 'lat' => $to->lat, 'lng' => $to->lng],
            'ticket' => $ticket,
            'disruptions' => $relevantDisruptions,
        ]);
    }

    /**
     * The nearest transit stop when the origin is within {@see self::AT_STOP_METRES}
     * of it — i.e. the user is basically on the platform, so the plan should
     * start there. GPS at a stop reads tens of metres off, hence a generous
     * radius. Null when nothing is that close (plan from the real origin).
     */
    private function atStopOrigin(GeoPoint $from): ?GeoPoint
    {
        $stop = GtfsStop::query()
            ->where('location_type', 0)
            ->whereNotNull('stop_lat')
            ->whereNotNull('stop_lng')
            ->selectRaw(
                'stop_lat, stop_lng, (ABS(stop_lat - ?) + ABS(stop_lng - ?)) as dist',
                [$from->lat, $from->lng],
            )
            ->orderBy('dist')
            ->first();

        if ($stop === null) {
            return null;
        }

        $metres = $this->metresBetween(
            $from->lat, $from->lng, (float) $stop->stop_lat, (float) $stop->stop_lng,
        );

        return $metres <= self::AT_STOP_METRES
            ? new GeoPoint((float) $stop->stop_lat, (float) $stop->stop_lng)
            : null;
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
