<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Profile\ProfileEngine;
use App\Services\DisruptionService;
use App\Services\UserLocationService;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Take me there" — the only journey-planning surface in v2. Every entity
 * (office, place, event) opens this with a destination; origin defaults
 * to the user's resolved location anchor chain (GPS → confirmed → home).
 * The response carries profile-driven ticket advice and disruptions
 * filtered to the journey's lines.
 */
class TakeMeThereController extends Controller
{
    public function __invoke(
        Request $request,
        RouteService $routes,
        ProfileEngine $profileEngine,
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
            $resolved = app(UserLocationService::class)->resolve($user, $request);
            $from = new GeoPoint((float) $resolved['lat'], (float) $resolved['lng']);
            $fromName = (string) ($resolved['name'] ?? 'Your location');
        }

        $to = new GeoPoint((float) $validated['to_lat'], (float) $validated['to_lng']);

        $result = $routes->plan($from, $to);

        $profile = $profileEngine->build($user);
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
            'ticket' => [
                'advice' => $profile->ticketAdvice->value,
                'label' => $profile->ticketAdvice->label(),
                'reason' => $profile->ticketAdvice->reason(),
            ],
            'disruptions' => $relevantDisruptions,
        ]);
    }
}
