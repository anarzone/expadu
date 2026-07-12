<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransportMode;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Spot;
use App\Services\NearbyPlaces;
use App\Services\UserLocationService;
use App\Support\EventOccurrencePresenter;
use App\Transit\Dto\GeoPoint;
use App\Transit\TravelTimes;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/events?window=today|tomorrow|weekend|week&category=&veedel=&free=1
 *
 * Occurrences in the window, chronological — pure DB reads over fields
 * stored at ingest; this path makes zero AI calls. The composer uses
 * the same Event::occurringBetween scope internally.
 */
class EventsController extends Controller
{
    private const MAX_MATRIX_DESTINATIONS = 50;

    public function __construct(
        private readonly EventOccurrencePresenter $presenter,
        private readonly UserLocationService $locations,
        private readonly TravelTimes $travel,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'window' => ['nullable', 'in:today,tomorrow,weekend,week'],
            'category' => ['nullable', 'string', 'max:40'],
            'veedel' => ['nullable', 'string', 'max:100'],
            'free' => ['nullable', 'boolean'],
            'venue' => ['nullable', 'integer'], // venue id — the place strip deep-link
            'sort' => ['nullable', 'in:soonest,nearest,recommended'],
            'mode' => ['nullable', 'in:walk,bike,transit'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);

        [$from, $to] = $this->window($validated['window'] ?? 'today');

        $occurrences = Event::occurringBetween($from, $to)
            ->when($validated['category'] ?? null, function ($occurrences, $category) {
                $accepted = $this->categoryValues($category);

                return $occurrences->filter(fn (array $o) => in_array($o['event']->category, $accepted, true));
            })
            ->when($validated['veedel'] ?? null, fn ($occurrences, $veedel) => $occurrences
                ->filter(fn (array $o) => $o['event']->venue?->veedel === $veedel))
            ->when($validated['free'] ?? false, fn ($occurrences) => $occurrences
                ->filter(fn (array $o) => $o['event']->is_free
                    || strtolower((string) $o['event']->price_text) === 'free'))
            ->when($validated['venue'] ?? null, fn ($occurrences, $venueId) => $occurrences
                ->filter(fn (array $o) => $o['event']->venue_id === (int) $venueId))
            ->values();

        $origin = $this->locations->context($request->user(), $request);
        $travelMinutes = $this->travelMinutes(
            $occurrences->all(),
            $origin->toGeoPoint(),
            isset($validated['mode']) ? TransportMode::from($validated['mode']) : TransportMode::Walk,
        );

        $presented = $occurrences->map(function (array $occurrence, int $index) use ($origin, $travelMinutes): array {
            $event = $occurrence['event'];
            $result = $this->presenter->present($event, $occurrence['starts_at'], $occurrence['ends_at']);
            $lat = $event->venue?->lat ?? $event->lat;
            $lng = $event->venue?->lng ?? $event->lng;
            $result['distance_km'] = $origin->hasOrigin() && $lat !== null && $lng !== null
                ? round(NearbyPlaces::km($origin->lat, $origin->lng, (float) $lat, (float) $lng), 2)
                : null;
            $result['travel_min'] = $travelMinutes[$index] ?? null;
            $result['_rank'] = [
                'relevance' => $event->relevance,
                'quality' => (float) ($event->quality_score ?? 0),
                'starts_at' => $occurrence['starts_at']->timestamp,
            ];

            return $result;
        });

        if (($validated['sort'] ?? 'soonest') === 'nearest') {
            $presented = $presented
                ->sortBy(fn (array $event): float => $event['distance_km'] ?? INF)
                ->values();
        }

        if (($validated['sort'] ?? 'soonest') === 'recommended') {
            $presented = $presented
                ->sort(function (array $left, array $right): int {
                    $byRelevance = ($right['_rank']['relevance'] ?? -1) <=> ($left['_rank']['relevance'] ?? -1);
                    if ($byRelevance !== 0) {
                        return $byRelevance;
                    }

                    $leftProximity = $left['travel_min'] ?? (($left['distance_km'] ?? INF) * 12);
                    $rightProximity = $right['travel_min'] ?? (($right['distance_km'] ?? INF) * 12);

                    return ($leftProximity <=> $rightProximity)
                        ?: ($left['_rank']['starts_at'] <=> $right['_rank']['starts_at'])
                        ?: ($right['_rank']['quality'] <=> $left['_rank']['quality']);
                })
                ->values();
        }

        $presented = $presented->map(function (array $event): array {
            unset($event['_rank']);

            return $event;
        });

        return response()->json([
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'origin' => [
                'source' => $origin->source->value,
                'label' => $origin->label,
            ],
            'needs_location' => ! $origin->hasOrigin(),
            'data' => $presented->all(),
        ]);
    }

    /**
     * @param  list<array{event: Event}>  $occurrences
     * @return list<int|null>
     */
    private function travelMinutes(array $occurrences, ?GeoPoint $origin, TransportMode $mode): array
    {
        if ($origin === null || $mode === TransportMode::Transit) {
            return array_fill(0, count($occurrences), null);
        }

        $destinations = [];
        $destinationOffsets = [];
        $occurrenceDestinationKeys = [];
        foreach ($occurrences as $index => $occurrence) {
            $event = $occurrence['event'];
            $lat = $event->venue?->lat ?? $event->lat;
            $lng = $event->venue?->lng ?? $event->lng;
            if ($lat !== null && $lng !== null) {
                $key = sprintf('%.5f,%.5f', $lat, $lng);
                if (! array_key_exists($key, $destinationOffsets)) {
                    if (count($destinations) >= self::MAX_MATRIX_DESTINATIONS) {
                        continue;
                    }

                    $destinationOffsets[$key] = count($destinations);
                    $destinations[] = new GeoPoint((float) $lat, (float) $lng);
                }
                $occurrenceDestinationKeys[$index] = $key;
            }
        }

        $result = array_fill(0, count($occurrences), null);
        $minutes = $this->travel->minutes($mode, $origin, $destinations);
        foreach ($occurrenceDestinationKeys as $occurrenceIndex => $key) {
            $result[$occurrenceIndex] = $minutes[$destinationOffsets[$key]] ?? null;
        }

        return $result;
    }

    /**
     * GET /api/places/{spot}/events — what's happening at this place in
     * the next 7 days (the Places-detail strip).
     */
    public function place(Spot $spot): JsonResponse
    {
        $from = CarbonImmutable::now('Europe/Berlin');
        $to = $from->addDays(7)->endOfDay();

        $occurrences = Event::occurringBetween($from, $to)
            ->filter(fn (array $o) => $o['event']->venue?->place_id === $spot->id)
            ->values();

        return response()->json([
            'count' => $occurrences->count(),
            'data' => $occurrences
                ->take(5)
                ->map(fn (array $o) => $this->presenter->present($o['event'], $o['starts_at'], $o['ends_at']))
                ->all(),
        ]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(string $window): array
    {
        $now = CarbonImmutable::now('Europe/Berlin');

        return match ($window) {
            'tomorrow' => [$now->addDay()->startOfDay(), $now->addDay()->endOfDay()],
            'weekend' => [
                $now->isWeekend() ? $now : $now->next('Saturday')->startOfDay(),
                ($now->isSunday() ? $now : $now->next('Sunday'))->endOfDay(),
            ],
            'week' => [$now, $now->addDays(7)->endOfDay()],
            default => [$now, $now->endOfDay()],
        };
    }

    /**
     * A new-taxonomy filter also matches its legacy synonyms so
     * pre-pivot rows stay filterable until reprocessed.
     *
     * @return list<string>
     */
    private function categoryValues(string $category): array
    {
        return match ($category) {
            'language_exchange' => ['language_exchange', 'language'],
            'intl_meetup' => ['intl_meetup', 'social'],
            'culture' => ['culture', 'music'],
            'sports' => ['sports'],
            'party' => ['party'],
            'stammtisch' => ['stammtisch'],
            default => [$category],
        };
    }
}
