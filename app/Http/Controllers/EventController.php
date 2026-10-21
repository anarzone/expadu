<?php

namespace App\Http\Controllers;

use App\Day\Contracts\RecordedAppointments;
use App\Day\DayProjection;
use App\Enums\EventCategory;
use App\Models\ComposerWorkspace;
use App\Models\Event;
use App\Models\FavouriteStop;
use App\Models\User;
use App\Services\WeatherService;
use App\Support\EventOccurrencePresenter;
use App\Support\EventStartingPoint;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        $input = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            // A listing's details (?event=&start=) open on that listing's day.
            'event' => ['nullable', 'integer'],
            'start' => ['nullable', 'date', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
        ]);
        $today = CarbonImmutable::now('Europe/Berlin');
        $day = match (true) {
            isset($input['date']) => CarbonImmutable::parse($input['date'], 'Europe/Berlin'),
            isset($input['start']) => CarbonImmutable::parse($input['start'])->setTimezone('Europe/Berlin')->startOfDay(),
            default => $today,
        };
        $filters = $this->filters($request);
        // The URL's starting point counts only as a public point or the
        // person's own saved place; anything else is dropped.
        $from = $points->resolve($filters['from'], $request->user());
        $filters['from'] = $from['key'] ?? null;

        if ($from === null && $filters['sort'] === 'closest') {
            $filters['sort'] = 'soonest';
        }

        // The day's listings, the catalogue, the saved places and the appointment are
        // read only when the response carries them, not again for Get there's
        // `only: ['day']` or the deferred weather.
        return Inertia::render('events', [
            'date' => $day->toDateString(),
            'today' => $today->toDateString(),
            'filters' => $filters,
            'starting_point' => $from,
            'saved_places' => fn () => $points->savedPlaces($request->user()),
            // Get there's "Where do you start?": the stops starred in Travel.
            'favourite_stops' => fn () => $this->favouriteStops($request->user(), $points),
            'appointment' => fn () => $day->isSameDay($today) ? $this->appointment($request, $today) : null,
            // The day shown, as Travel reads it: what Get there checks a way
            // against ("My day"). Only when Get there asks for it; a past day
            // has nothing to plan.
            'day' => $day->lessThan($today->startOfDay())
                ? null
                : Inertia::optional(fn () => app(DayProjection::class)->for($request->user(), $day->toDateString(), $request)),
            'catalogue' => fn () => $this->catalogue(),
            'listings' => fn () => $this->withVenueFacts(Event::occurringBetween($day->startOfDay(), $day->endOfDay())
                ->map(fn (array $o) => $this->presenter->present($o['event'], $o['starts_at'], $o['ends_at']))
                ->values()
                ->all()),
            // The street's sky and the rain warnings; the page draws a plain
            // sky until it arrives, and says so when there is none.
            'weather' => Inertia::defer(fn () => app(WeatherService::class)->getDayForecast($day->toDateString())),
            // A past day points on to the next day with listings.
            'next_listed' => $day->lessThan($today->startOfDay())
                ? Inertia::defer(fn () => $this->nextListed($today), 'notes')
                : null,
        ]);
    }

    /**
     * The first day from today with listings, within 60 days: the coming
     * week first (nearly always enough), then the rest.
     */
    private function nextListed(CarbonImmutable $today): ?string
    {
        foreach ([[0, 6], [7, 59]] as [$from, $to]) {
            $first = Event::occurringBetween($today->addDays($from)->startOfDay(), $today->addDays($to)->endOfDay())
                ->min(fn (array $o) => $o['starts_at']->setTimezone('Europe/Berlin')->toDateString());

            if (is_string($first)) {
                return $first;
            }
        }

        return null;
    }

    /**
     * What the filters offer: every category and area the listed events
     * have, on any day, as the mock's pickers do (its whole catalogue), so a
     * choice can point to another day. The same for everyone and changed only
     * by an import or a moderator, so it is read once every five minutes.
     *
     * @return array{categories: list<string>, areas: list<string>}
     */
    private function catalogue(): array
    {
        return Cache::remember('events:catalogue', now()->addMinutes(5), function (): array {
            $categories = Event::query()->visible()->distinct()->pluck('category')
                ->map(fn (?string $category) => EventCategory::fromLegacy($category)->value)
                ->unique()->values()->all();
            $areas = Event::query()->visible()
                ->join('venues', 'venues.id', '=', 'events.venue_id')
                ->whereNotNull('venues.veedel')
                ->distinct()
                ->orderBy('venues.veedel')
                ->pluck('venues.veedel')
                ->all();

            return ['categories' => $categories, 'areas' => $areas];
        });
    }

    /**
     * The person's own last appointment today that has an end (recorded in
     * Paperwork, or planned in their day), for the details' "For you" (how an
     * event sits after it) and Get there's start (where it is, when they
     * recorded a place on the map). Only what they recorded; nothing when none.
     *
     * @return array{title: string, end: string, place: array{name: string, lat: float, lng: float}|null}|null
     */
    /**
     * The stops the person starred in Travel, where the timetable knows them,
     * as starting points (at most six). A name it doesn't know is left out.
     *
     * @return list<array{key: string, name: string, lat: float, lng: float}>
     */
    private function favouriteStops(?User $user, EventStartingPoint $points): array
    {
        if ($user === null) {
            return [];
        }

        $names = FavouriteStop::query()->where('user_id', $user->id)->orderBy('id')->limit(6)->pluck('name')->all();
        $found = $points->publicMany($names);

        return array_values(array_filter(array_map(
            fn (string $name) => $found[mb_strtolower(trim($name))] ?? null,
            $names,
        )));
    }

    private function appointment(Request $request, CarbonImmutable $today): ?array
    {
        $user = $request->user();
        $place = fn (?string $name, mixed $lat, mixed $lng) => $name !== null && $name !== '' && is_numeric($lat) && is_numeric($lng)
            ? ['name' => $name, 'lat' => (float) $lat, 'lng' => (float) $lng]
            : null;
        $recorded = collect(app(RecordedAppointments::class)->between($user, $today->startOfDay(), $today->endOfDay()))
            ->filter(fn ($item) => $item->endAt !== null)
            ->map(fn ($item) => ['title' => $item->title, 'end' => $item->endAt, 'place' => $place($item->locationLabel, $item->lat, $item->lng)]);
        // An official appointment the person planned their day around (as
        // DayProjection reads plans): it carries its end.
        $planned = collect(ComposerWorkspace::query()->where('user_id', $user->id)->first()?->state['plans'][$today->toDateString()]['slots'] ?? [])
            ->filter(fn (array $slot) => ($slot['type'] ?? null) === 'appointment' && is_string($slot['end_at'] ?? null) && is_string($slot['name'] ?? null))
            ->map(fn (array $slot) => ['title' => $slot['name'], 'end' => CarbonImmutable::parse($slot['end_at']), 'place' => $place(is_string($slot['location'] ?? null) ? $slot['location'] : null, $slot['lat'] ?? null, $slot['lng'] ?? null)]);
        $last = $recorded->merge($planned)->sortBy(fn (array $a) => $a['end'])->last();

        return $last === null ? null : [
            'title' => $last['title'],
            'end' => $last['end']->setTimezone('Europe/Berlin')->format('H:i'),
            'place' => $last['place'],
        ];
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
