<?php

namespace App\Home;

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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What's on: the day's listings in a panel over Today (owner, 27 Oct 2026: no Events page of its
 * own, "I don't want to build the next one"), with the Events page's filters and details. One day
 * at a time, earlier hours included; the filters narrow it in the browser. Read only when the
 * panel is open (?whats_on=1), so Today's own response never waits on it.
 */
final class WhatsOn
{
    public function __construct(private EventOccurrencePresenter $presenter, private EventStartingPoint $points) {}

    /** Today with the panel open, on a day, filters or a listing's details (`event`, `start`). */
    public static function url(array $query = []): string
    {
        return '/today?'.http_build_query(['whats_on' => 1, ...array_filter($query, fn ($value) => $value !== null && $value !== '')]);
    }

    /** One listing's details: its occurrence's day, open on it. */
    public static function eventUrl(int $event, CarbonImmutable $start): string
    {
        $start = $start->setTimezone('Europe/Berlin');

        return self::url(['date' => $start->toDateString(), 'event' => $event, 'start' => $start->toIso8601String()]);
    }

    /** @return array<string, mixed> */
    public function for(Request $request): array
    {
        $user = $request->user();
        $today = CarbonImmutable::now('Europe/Berlin');
        $start = $this->instant($request->query('start'));
        $event = filter_var($request->query('event'), FILTER_VALIDATE_INT) ?: null;
        // A listing without its start (an older link) opens on its next occurrence.
        if ($event !== null && $start === null) {
            $start = Event::occurringBetween($today->startOfDay(), $today->addYear(), [$event])
                ->first(fn (array $o) => $o['starts_at']->isFuture())['starts_at'] ?? null;
        }
        $date = $this->day($request->query('date'));
        $day = match (true) {
            $date !== null => CarbonImmutable::parse($date, 'Europe/Berlin'),
            $start !== null => $start->setTimezone('Europe/Berlin')->startOfDay(),
            default => $today,
        };
        $filters = $this->filters($request);
        // The URL's starting point counts only as a public point or the person's own saved place.
        $from = $this->points->resolve($filters['from'], $user);
        $filters['from'] = $from['key'] ?? null;
        if ($from === null && $filters['sort'] === 'closest') {
            $filters['sort'] = 'soonest';
        }
        $past = $day->lessThan($today->startOfDay());

        return [
            'date' => $day->toDateString(),
            'today' => $today->toDateString(),
            'filters' => $filters,
            // The listing whose details the URL opens, as the page reads it back.
            'event' => $event !== null && $start !== null ? ['event' => $event, 'start' => $start->setTimezone('Europe/Berlin')->toIso8601String()] : null,
            'starting_point' => $from,
            'saved_places' => $this->points->savedPlaces($user),
            // Get there's "Where do you start?": the stops starred in Travel.
            'favourite_stops' => $this->favouriteStops($user),
            'appointment' => $day->isSameDay($today) ? $this->appointment($user, $today) : null,
            // Get there checks a way against the day it's on: Today's own day when it's today,
            // else that day's, read only when a listing's details are open.
            'day' => ! $past && ! $day->isSameDay($today) && $event !== null
                ? app(DayProjection::class)->for($user, $day->toDateString(), $request) : null,
            'catalogue' => $this->catalogue(),
            'listings' => $this->withVenueFacts(Event::occurringBetween($day->startOfDay(), $day->endOfDay())
                ->map(fn (array $o) => $this->presenter->present($o['event'], $o['starts_at'], $o['ends_at']))
                ->values()
                ->all()),
            // The rain warnings, from the forecast's cache (WeatherService never waits on a provider).
            'weather' => app(WeatherService::class)->getDayForecast($day->toDateString()),
            // A past day points on to the next day with listings.
            'next_listed' => $past ? $this->nextListed($today) : null,
        ];
    }

    /** A real calendar day, YYYY-MM-DD; anything else (2026-02-31) is none. */
    private function day(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^20\d\d-\d\d-\d\d$/', $value) !== 1) {
            return null;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }

    private function instant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            $at = CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }

        return $at->year >= 2000 && $at->year < 2100 ? $at : null;
    }

    /**
     * The first day from today with listings, within 60 days: the coming week first (nearly
     * always enough), then the rest. The same for everyone, so it's read once every five minutes.
     */
    private function nextListed(CarbonImmutable $today): ?string
    {
        $first = Cache::remember('events:next-listed:'.$today->toDateString(), now()->addMinutes(5), function () use ($today): string {
            foreach ([[0, 6], [7, 59]] as [$from, $to]) {
                $first = Event::occurringBetween($today->addDays($from)->startOfDay(), $today->addDays($to)->endOfDay())
                    ->min(fn (array $o) => $o['starts_at']->setTimezone('Europe/Berlin')->toDateString());
                if (is_string($first)) {
                    return $first;
                }
            }

            return '';
        });

        return $first === '' ? null : $first;
    }

    /**
     * What the filters offer: every category and area the listed events have, on any day, so a
     * choice can point to another day. The same for everyone, read once every five minutes.
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
     * The stops the person starred in Travel, where the timetable knows them, as starting points
     * (at most six). A name it doesn't know is left out.
     *
     * @return list<array{key: string, name: string, lat: float, lng: float}>
     */
    private function favouriteStops(User $user): array
    {
        $names = FavouriteStop::query()->where('user_id', $user->id)->orderBy('id')->limit(6)->pluck('name')->all();
        $found = $this->points->publicMany($names);

        return array_values(array_filter(array_map(fn (string $name) => $found[mb_strtolower(trim($name))] ?? null, $names)));
    }

    /**
     * The person's own last appointment today that has an end (recorded in Paperwork, or planned
     * in their day), for the details' "For you" and Get there's start. Only what they recorded.
     *
     * @return array{title: string, end: string, place: array{name: string, lat: float, lng: float}|null}|null
     */
    private function appointment(User $user, CarbonImmutable $today): ?array
    {
        $place = fn (?string $name, mixed $lat, mixed $lng) => $name !== null && $name !== '' && is_numeric($lat) && is_numeric($lng)
            ? ['name' => $name, 'lat' => (float) $lat, 'lng' => (float) $lng]
            : null;
        $recorded = collect(app(RecordedAppointments::class)->between($user, $today->startOfDay(), $today->endOfDay()))
            ->filter(fn ($item) => $item->endAt !== null)
            ->map(fn ($item) => ['title' => $item->title, 'end' => $item->endAt, 'place' => $place($item->locationLabel, $item->lat, $item->lng)]);
        // An official appointment the person planned their day around (as DayProjection reads plans).
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
     * The venue's recorded setting (indoor/outdoor) and step-free access, from place_features, in
     * one query for the day: what the rain warning and the details' Accessibility read.
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
     * The filters from the URL, so a link opens the same view. Lenient: a value it doesn't know is
     * dropped, not an error.
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
}
