<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventCategory;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Spot;
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
                ->map(fn (array $o) => $this->shape($o['event'], $o['starts_at'], $o['ends_at']))
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
                ->map(fn (array $o) => $this->shape($o['event'], $o['starts_at'], $o['ends_at']))
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
     * The events card contract — time-first, server-built meta.
     *
     * @return array<string, mixed>
     */
    private function shape(Event $event, CarbonImmutable $startsAt, ?CarbonImmutable $endsAt): array
    {
        $category = EventCategory::fromLegacy($event->category);
        $venue = $event->venue;

        return [
            'id' => $event->id,
            'occurrence_start' => $startsAt->toIso8601String(),
            'occurrence_end' => $endsAt?->toIso8601String(),
            'title' => $event->title_en ?: $event->title,
            'category' => $category->value,
            'category_label' => $category->label(),
            'emoji' => $category->emoji(),
            'meta' => $this->meta($startsAt, $venue?->veedel),
            'chips' => $this->chips($event),
            'tip' => $event->tip_en,
            'summary' => $event->summary_en ?: ($event->description_en ?: null),
            'price_text' => $event->price_text ?: ($event->is_free ? 'free' : null),
            'venue' => [
                'name' => $venue->name ?? $event->location_name,
                'veedel' => $venue->veedel ?? null,
                'lat' => $venue->lat ?? $event->lat,
                'lng' => $venue->lng ?? $event->lng,
                'place_id' => $venue->place_id ?? null,
                'place_name' => $venue?->place?->name,
            ],
            'source_url' => $event->source_url,
            'is_recurring' => $event->recurrence !== null,
            'recurrence_text' => $this->recurrenceText($event),
            'verified' => $event->verified_at !== null,
        ];
    }

    private function meta(CarbonImmutable $startsAt, ?string $veedel): string
    {
        $now = CarbonImmutable::now('Europe/Berlin');

        $day = match (true) {
            $startsAt->isSameDay($now) => $startsAt->hour >= 17 ? 'Tonight' : 'Today',
            $startsAt->isSameDay($now->addDay()) => 'Tomorrow',
            default => $startsAt->format('l'),
        };

        return implode(' · ', array_filter([
            "{$day} {$startsAt->format('H:i')}",
            $veedel,
        ]));
    }

    /**
     * @return list<string>
     */
    private function chips(Event $event): array
    {
        $chips = is_array($event->chips) ? $event->chips : [];

        if (($event->is_free || strtolower((string) $event->price_text) === 'free')
            && ! in_array('free', $chips, true)) {
            $chips[] = 'free';
        }

        return array_values(array_unique($chips));
    }

    private function recurrenceText(Event $event): ?string
    {
        if (! $event->recurrence) {
            return null;
        }

        $day = CarbonImmutable::parse($event->starts_at)->format('l');

        return match (true) {
            str_contains($event->recurrence, 'FREQ=DAILY') => 'Daily',
            str_contains($event->recurrence, 'INTERVAL=4') => "Every 4 weeks on {$day}",
            str_contains($event->recurrence, 'INTERVAL=2') => "Every other {$day}",
            str_contains($event->recurrence, 'FREQ=WEEKLY') => "Every {$day}",
            str_contains($event->recurrence, 'FREQ=MONTHLY') => 'Monthly',
            default => 'Recurring',
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
