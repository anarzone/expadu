<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DisruptionService;
use App\Services\UserLocationService;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\FareAdvisor;
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
        ]);

        $user = $request->user();

        if (isset($validated['from_lat'], $validated['from_lng'])) {
            $from = new GeoPoint((float) $validated['from_lat'], (float) $validated['from_lng']);
            $fromName = 'Your location';
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

        $result = $routes->plan($from, $to);

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
}
