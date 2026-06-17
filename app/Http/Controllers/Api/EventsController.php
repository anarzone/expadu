<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Spot;
use App\Support\EventOccurrencePresenter;
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
    public function __construct(private EventOccurrencePresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'window' => ['nullable', 'in:today,tomorrow,weekend,week'],
            'category' => ['nullable', 'string', 'max:40'],
            'veedel' => ['nullable', 'string', 'max:100'],
            'free' => ['nullable', 'boolean'],
            'venue' => ['nullable', 'integer'], // venue id — the place strip deep-link
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

        return response()->json([
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'data' => $occurrences
                ->map(fn (array $o) => $this->presenter->present($o['event'], $o['starts_at'], $o['ends_at']))
                ->all(),
        ]);
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
