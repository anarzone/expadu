<?php

namespace App\Http\Controllers;

use App\Enums\EventCategory;
use App\Models\Event;
use App\Services\WeatherService;
use App\Support\EventOccurrencePresenter;
use App\Support\EventStartingPoint;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Events: the day's listings on the street (the mock's events-next). The
 * page shows one day at a time, the whole day, earlier hours included;
 * filters narrow the wall in the browser, as in the mock.
 */
class EventController extends Controller
{
    public function __construct(private readonly EventOccurrencePresenter $presenter) {}

    public function index(Request $request, EventStartingPoint $points): Response
    {
        $input = $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01']]);
        $today = CarbonImmutable::now('Europe/Berlin');
        $day = isset($input['date']) ? CarbonImmutable::parse($input['date'], 'Europe/Berlin') : $today;
        $filters = $this->filters($request);
        // The URL's starting point counts only as a public point or the
        // person's own saved place; anything else is dropped.
        $from = $points->resolve($filters['from'], $request->user());
        $filters['from'] = $from['key'] ?? null;

        if ($from === null && $filters['sort'] === 'closest') {
            $filters['sort'] = 'soonest';
        }

        return Inertia::render('events', [
            'date' => $day->toDateString(),
            'today' => $today->toDateString(),
            'filters' => $filters,
            'starting_point' => $from,
            'saved_places' => $points->savedPlaces($request->user()),
            'listings' => $this->withVenueFacts(Event::occurringBetween($day->startOfDay(), $day->endOfDay())
                ->map(fn (array $o) => $this->presenter->present($o['event'], $o['starts_at'], $o['ends_at']))
                ->values()
                ->all()),
            // The street's sky and the rain warnings; the page draws a plain
            // sky until it arrives, and says so when there is none.
            'weather' => Inertia::defer(fn () => app(WeatherService::class)->getDayForecast($day->toDateString())),
        ]);
    }

    /**
     * The venue's recorded setting (indoor/outdoor) and step-free access,
     * from place_features, in one query for the day: what the street's rain
     * warning and the poster's Accessibility read. Unknown stays null.
     *
     * @param  list<array<string, mixed>>  $listings
     * @return list<array<string, mixed>>
     */
    private function withVenueFacts(array $listings): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (array $l) => $l['venue']['place_id'] ?? null, $listings))));
        $facts = $ids === [] ? collect() : DB::table('place_features')->whereIn('spot_id', $ids)->get(['spot_id', 'setting', 'wheelchair'])->keyBy('spot_id');

        return array_map(function (array $listing) use ($facts): array {
            $fact = $facts->get($listing['venue']['place_id'] ?? 0);
            $listing['venue']['setting'] = $fact?->setting;
            $listing['venue']['wheelchair'] = $fact?->wheelchair;

            return $listing;
        }, $listings);
    }

    /**
     * The page's filters from its URL, so a deep link opens the same view.
     * Lenient: a value it doesn't know is dropped, not an error.
     *
     * @return array{q: string, category: ?string, area: ?string, free: bool, saved: bool, sort: string, from: ?string}
     */
    private function filters(Request $request): array
    {
        $text = fn (string $key, int $max): ?string => is_string($value = $request->query($key)) && trim($value) !== ''
            ? mb_substr(trim($value), 0, $max)
            : null;
        $category = EventCategory::tryFrom((string) $request->query('category'));

        return [
            'q' => $text('q', 100) ?? '',
            'category' => $category?->value,
            'area' => $text('area', 100),
            'free' => $request->boolean('free'),
            'saved' => $request->boolean('saved'),
            'sort' => in_array($request->query('sort'), ['soonest', 'evening', 'closest'], true) ? $request->query('sort') : 'soonest',
            'from' => $text('from', 120),
        ];
    }

    /**
     * Legacy detail URLs — the v2 page expands inline, no detail route.
     */
    public function show(Request $request, Event $event): RedirectResponse
    {
        abort_unless(Event::query()->visible()->whereKey($event->id)->exists(), 404);
        $input = $request->validate(['start' => ['nullable', 'date', 'after_or_equal:2000-01-01', 'before:2100-01-01']]);
        $start = isset($input['start']) ? CarbonImmutable::parse($input['start'])->setTimezone('Europe/Berlin') : null;
        if ($start === null) {
            $now = CarbonImmutable::now('Europe/Berlin');
            $start = Event::occurringBetween($now->startOfDay(), $now->addYear(), [$event->id])
                ->first(fn ($item) => $item['starts_at']->isFuture())['starts_at'] ?? CarbonImmutable::parse($event->starts_at);
        }

        return redirect()->route('events', ['event' => $event->id, 'start' => $start->toIso8601String(), 'date' => $start->toDateString()]);
    }

    public function saved(Request $request): RedirectResponse
    {
        return redirect()->route('events', ['saved' => 1]);
    }

    public function join(Request $request, Event $event): RedirectResponse
    {
        $event->attendees()->syncWithoutDetaching([
            $request->user()->id => ['joined_at' => now()],
        ]);

        return back();
    }

    public function leave(Request $request, Event $event): RedirectResponse
    {
        $event->attendees()->detach($request->user()->id);

        return back();
    }
}
