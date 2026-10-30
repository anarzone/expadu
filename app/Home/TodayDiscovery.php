<?php

namespace App\Home;

use App\Day\DayItem;
use App\Day\DayProjection;
use App\Enums\SpotCategory;
use App\Enums\SpotFeedbackState;
use App\Models\Event;
use App\Models\SpotFeedback;
use App\Models\User;
use App\Personalisation\Affinities;
use App\Personalisation\ListImpressions;
use App\Personalisation\SignalItemType;
use App\Personalisation\TasteFacts;
use App\Personalisation\TodayRanker;
use App\Personalisation\TodayTaste;
use App\Places\OpenNearby;
use App\Saved\SavedCollection;
use App\Services\NearbyPlaces;
use App\Support\EventOccurrencePresenter;
use App\Support\EventStartingPoint;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Today's discovery (the mock's "A little Cologne" and "Also in ‹area›"),
 * from listings and the person's own saved ideas: today's events still to
 * come or still on that fit around the person's plans, those near their
 * Veedel (or Home) first, then tomorrow's only once nothing is left today;
 * and the everyday places whose listed hours say they're open near the next
 * plan when it ends, or, with no plan to go by, open now near their Veedel
 * or Home (owner, 24 Oct 2026). Nothing is sample or guessed; with nothing
 * listed a block is simply absent.
 */
final class TodayDiscovery
{
    /**
     * A listing with no end (#257) stays as long as Events keeps it
     * (ExpireEvents: four hours after its start). It shows by its start and
     * "end not listed", never as on now.
     */
    private const NO_END_HOURS = 4;

    /** Within this of the person's Veedel (or Home), an event is near and comes first. */
    private const NEAR_KM = 3.0;

    /** How long a place's row is kept for its 5-minute slot (rowsAt()). */
    private const PLACE_ROW_SECONDS = 360;

    /** How long a Veedel's centre is kept (home()): public, and it doesn't move. */
    private const VEEDEL_SECONDS = 86400;

    /** Today's listings at most: two posters, three compact, six for the room under the departures. */
    private const LISTED = 11;

    /** The rule order's version, logged with each list shown (ListImpressions): change it when events() or the places' order changes. */
    public const RULES = 'rules-1';

    /** How long a day's listings are kept (listings()). */
    private const LISTINGS_SECONDS = 300;

    /** The kinds the mock looks for near a plan, one place each, in this order. */
    private const KINDS = [
        'bakery' => [SpotCategory::Bakery],
        'cafe' => [SpotCategory::Cafe],
        'pharmacy' => [SpotCategory::Pharmacy],
        // Obst & Gemüse counts as groceries, as on Around here.
        'grocery' => [SpotCategory::Supermarket, SpotCategory::Convenience, SpotCategory::Drugstore, SpotCategory::Greengrocer],
    ];

    public function __construct(
        private DayProjection $days,
        private EventOccurrencePresenter $presenter,
        private OpenNearby $open,
        private SavedCollection $saved,
        private EventStartingPoint $points,
        private TodayTaste $taste,
        private ListImpressions $impressions,
        private Affinities $affinities,
        private TasteFacts $facts,
    ) {}

    /**
     * @param  bool  $shown  the person is about to see it (Today's own render, not the ranker's read): its lists are logged
     * @return array{part: string, events: list<array<string, mixed>>, around: array<string, mixed>|null}
     */
    public function for(User $user, ?CarbonImmutable $now = null, bool $shown = false): array
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone(DayProjection::TIMEZONE);
        $active = collect($this->days->itemsOn($user, $now->format('Y-m-d')))
            ->filter(fn (DayItem $item) => ! $item->completed() && ($item->endAt ?? $item->startAt)?->greaterThanOrEqualTo($now))
            ->sortBy(fn (DayItem $item) => $item->startAt?->getTimestamp() ?? PHP_INT_MAX)
            ->values();

        $home = $this->home($user);
        // What they turned down ("Not for me") stays off Today while it's hidden.
        $hidden = $user->personalises() ? $this->affinities->for($user) : null;
        $events = $this->events($now, $active, $home)
            ->reject(fn (array $event) => $hidden?->isHidden(SignalItemType::Event->value, (int) $event['id']) ?? false)
            ->values();
        // "Also in ‹area›" is for after today's plan: tomorrow's listings stay out of it.
        $around = $this->around($user, $now, $active, $events->reject(fn (array $event) => isset($event['day'])))
            // No plan stop with a place to go by: open now near their Veedel or Home.
            ?? ($home !== null && ! $active->contains(fn (DayItem $item) => $item->routable() && $item->locationLabel !== null)
                ? $this->openNear($user, $now, $home)
                : null);
        $idea = $around['idea']['id'] ?? null;
        $ideaStart = $around['idea']['occurrence_start'] ?? null;

        // Today's order as the AI put it for them (TodayTaste, ranked in the
        // background), or null for this order. It only reorders what's shown.
        $order = $this->taste->order($user);

        // Posters for the first two eligible events, compact listings for
        // the next three (coordinator, 20 Oct), and up to six more for the
        // room under the departures (`more`, owner 28 Oct 2026: the page
        // fills around the board, which never moves). Posters fill first,
        // and the event "Also in ‹area›" suggests is in none.
        $eventKey = fn (array $event) => 'e'.$event['id'].'@'.CarbonImmutable::parse($event['occurrence_start'])->getTimestamp();
        $placeKey = fn (array $place) => 'p'.$place['id'];
        $candidates = $events->reject(fn (array $event) => $event['id'] === $idea && $event['occurrence_start'] === $ideaStart)->take(self::LISTED)->values();
        // Each AI pick with a why also says which of their leanings it matches, as code reads
        // them (reasons()): "Why am I seeing this?" shows those, the why only words one.
        $reasons = $order === null ? null : $this->reasons($user);
        $listed = self::ranked($candidates, $order['events'] ?? null, $order['why'] ?? [], $eventKey)
            ->map(fn (array $event, int $index) => [...$event, 'layout' => match (true) {
                $index < 2 => 'poster',
                $index < 5 => 'compact',
                default => 'more',
            }, ...(isset($event['why']) && $reasons !== null ? ['reasons' => $reasons($event)] : [])]);
        $places = collect($around['places'] ?? []);
        if ($around !== null) {
            $around['places'] = self::ranked($places, $order['places'] ?? null, $order['why'] ?? [], $placeKey)->all();
        }

        if ($shown) {
            [$policy, $version] = $order === null ? ['rule', self::RULES] : ['haiku', (string) ($order['version'] ?? TodayRanker::VERSION)];
            $this->impressions->record($user, 'today_events', $now, $policy, $version, $candidates->map($eventKey)->all(),
                $listed->map(fn (array $event) => ['key' => $eventKey($event), 'layout' => $event['layout']])->all());
            $this->impressions->record($user, 'today_places', $now, $policy, $version, $places->map($placeKey)->all(),
                collect($around['places'] ?? [])->map(fn (array $place) => ['key' => $placeKey($place)])->all());
        }

        $onlyTomorrow = $listed->isNotEmpty() && $listed->every(fn (array $event) => isset($event['day']));

        return [
            // A free day's heading (Today decides it's free): "Ideas for
            // this afternoon", or "for tomorrow" when that's all there is.
            'part' => $onlyTomorrow ? 'tomorrow' : ($now->hour < 12 ? 'morning' : ($now->hour < 17 ? 'afternoon' : 'evening')),
            'events' => $listed->all(),
            'around' => $around,
        ];
    }

    /**
     * Items in the order the AI gave (its ids, TodayRanker::candidates'
     * keys): those it names first, in its order, then the rest as they
     * were. It ranks, never hides: an item it doesn't name keeps its place
     * after, and an id it names that isn't here is ignored. A pick with a
     * why carries it (`why`); no other does.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @param  list<string>|null  $ids  null: keep this order
     * @param  array<string, string>  $why
     * @param  Closure(array<string, mixed>): string  $key
     * @return Collection<int, array<string, mixed>>
     */
    private static function ranked(Collection $items, ?array $ids, array $why, Closure $key): Collection
    {
        if ($ids === null) {
            return $items->values();
        }

        $rank = array_flip(array_values(array_filter($ids, 'is_string')));
        $count = count($rank);

        return $items
            ->map(fn (array $item, int $index) => [$item, $rank[$key($item)] ?? $count + $index])
            ->sortBy(fn (array $pair) => $pair[1])
            ->map(fn (array $pair) => isset($why[$key($pair[0])]) ? [...$pair[0], 'why' => $why[$key($pair[0])]] : $pair[0])
            ->values();
    }

    /**
     * Which of the person's leanings an event matches, in words, from what personalisation learned
     * (TasteFacts) and what they looked at lately (TodayTaste): its kind, then its neighbourhood.
     *
     * @return Closure(array<string, mixed>): list<string>
     */
    private function reasons(User $user): Closure
    {
        $facts = $this->facts->for($user) ?? [];
        $recent = $this->taste->for($user);
        $leaning = fn (string $key): array => collect($facts[$key] ?? [])->whereIn('level', ['strong', 'some'])->pluck('name')->all();
        $kinds = $leaning('categories');
        $areas = $leaning('veedels');
        $lately = array_values(array_filter(array_map(fn (string $kind): ?string => TasteFacts::categoryLabel($kind), $recent['categories'])));

        return function (array $event) use ($kinds, $areas, $lately, $recent): array {
            $kind = TasteFacts::categoryLabel((string) ($event['category'] ?? ''));
            $area = $event['venue']['veedel'] ?? null;

            return array_values(array_filter([
                match (true) {
                    $kind !== null && in_array($kind, $kinds, true) => "You lean to {$kind}, from what you open, save and go to.",
                    $kind !== null && in_array($kind, $lately, true) => "You looked at {$kind} lately.",
                    default => null,
                },
                match (true) {
                    is_string($area) && in_array($area, $areas, true) => "You lean to what’s on in {$area}.",
                    is_string($area) && in_array($area, $recent['areas'], true) => "You looked at {$area} lately.",
                    default => null,
                },
            ]));
        };
    }

    /**
     * Today's events still to come or still on, not clashing with a plan
     * that's still ahead: those near the person's Veedel (or Home) first,
     * each group soonest first. Nothing is hidden for being far. A started
     * event is on while its listed end is ahead; with no end listed, while
     * Events keeps it (NO_END_HOURS). Only once nothing is left today do
     * tomorrow's events follow, each marked `day: tomorrow` (owner, 21 and
     * 24 Oct 2026).
     *
     * @param  Collection<int, DayItem>  $active
     * @param  array{name: string, key: string, lat: float, lng: float}|null  $home
     * @return Collection<int, array<string, mixed>>
     */
    private function events(CarbonImmutable $now, Collection $active, ?array $home): Collection
    {
        $today = $this->listings($now, $now)
            ->filter(function (array $listing) use ($now, $active): bool {
                ['start' => $start, 'end' => $end] = $listing;
                if ($start < $now->getTimestamp()) {
                    $until = $end ?? ($listing['time_listed'] ? $start + self::NO_END_HOURS * 3600 : null);
                    if ($until === null || $until <= $now->getTimestamp()) {
                        return false;
                    }
                }

                return ! $active->contains(fn (DayItem $plan) => $end !== null && $plan->startAt !== null && $plan->endAt !== null
                    && $start < $plan->endAt->getTimestamp() && $end > $plan->startAt->getTimestamp());
            })
            ->pluck('event')
            ->values();

        if ($today->isNotEmpty()) {
            return $this->nearFirst($today, $home);
        }

        // Today shows eleven at most, so only the first eleven are dressed.
        return $this->nearFirst($this->listings($now, $now->addDay())
            ->map(fn (array $listing) => [...$listing['event'], 'day' => 'tomorrow'])
            ->values(), $home)->take(self::LISTED)->values();
    }

    /**
     * Events near the person's point first, keeping each group's order. A
     * venue without a position counts as not near; nothing is dropped.
     *
     * @param  Collection<int, array<string, mixed>>  $events
     * @param  array{lat: float, lng: float}|null  $home
     * @return Collection<int, array<string, mixed>>
     */
    private function nearFirst(Collection $events, ?array $home): Collection
    {
        if ($home === null) {
            return $events;
        }

        [$near, $rest] = $events->partition(fn (array $event) => is_numeric($event['venue']['lat'] ?? null) && is_numeric($event['venue']['lng'] ?? null)
            && NearbyPlaces::km($home['lat'], $home['lng'], (float) $event['venue']['lat'], (float) $event['venue']['lng']) <= self::NEAR_KM);

        return $near->concat($rest)->values();
    }

    /**
     * The person's own point for ideas without a plan: their Veedel's
     * centre, else their Home. Null when they have neither.
     *
     * @return array{name: string, key: string, lat: float, lng: float}|null
     */
    private function home(User $user): ?array
    {
        $veedel = trim((string) $user->veedel);
        // A Veedel's centre is public and the same for everyone in it.
        $centre = $veedel !== '' ? Cache::remember('today-discovery:veedel:'.md5(mb_strtolower($veedel)), self::VEEDEL_SECONDS, fn () => $this->points->public($veedel)) : null;

        return $centre ?? $this->points->resolve('home', $user);
    }

    /**
     * With no plan to go by: everyday places open now near the person's
     * Veedel (or Home), one of each kind, as Around here lists them. Named
     * for that place ("Open now near Ehrenfeld"), never "around your plan".
     *
     * @param  array{name: string, key: string, lat: float, lng: float}  $home
     * @return array<string, mixed>|null
     */
    private function openNear(User $user, CarbonImmutable $now, array $home): ?array
    {
        // A thin area looks a little further: 2 km, then 3 km, stopping at the
        // first that finds any (the rows say how far).
        $places = $this->places($user, $home['lat'], $home['lng'], $now, [1.0, 2.0, 3.0]);

        return $places === [] ? null : [
            'anchor' => null,
            'near' => ['name' => $home['name'], 'key' => $home['key']],
            'at' => $now->format('H:i'),
            'idea' => null,
            'place' => null,
            'saved' => false,
            'places' => $places,
        ];
    }

    /**
     * One place of each everyday kind whose listed hours say it's open at
     * `$at`, nearest first, three at most: Around here's own rows. Each
     * radius in turn (km) until one finds any.
     *
     * Fast for Today's first response (owner, 26 Oct 2026: pages load whole):
     * the point picks the nearest places by id in one query and stays in
     * it; each place's row is shared by everyone for its 5-minute slot,
     * cached by the place alone (rowsAt()). The distance from the point, the
     * saved flag and what the person set aside are worked out here, never
     * stored.
     *
     * @param  list<float>  $radii
     * @return list<array<string, mixed>>
     */
    private function places(User $user, float $lat, float $lng, CarbonImmutable $at, array $radii = [1.0]): array
    {
        $saved = SpotFeedback::effectiveForUser($user->id)
            ->filter(fn (SpotFeedback $row): bool => $row->state === SpotFeedbackState::Saved)
            ->keys()->map(fn (mixed $id): int => (int) $id)->all();
        $families = collect(self::KINDS)->map(fn (array $kinds) => array_map(fn (SpotCategory $kind) => $kind->value, $kinds));
        $open = collect();

        foreach ($radii as $km) {
            // Further out, more of the nearest may be closed.
            $ids = $this->open->nearestIds($lat, $lng, $families->flatten()->all(), $km, (int) round(32 * $km * $km), $user);
            $rows = $this->rowsAt($ids, $at);
            $open = $families
                ->map(fn (array $kinds) => $rows->first(fn (array $row) => in_array($row['kind'], $kinds, true) && $row['hours']['state'] === 'open'))
                ->filter();

            if ($open->isNotEmpty()) {
                break;
            }
        }

        return $open
            ->map(fn (array $row) => [...$row, 'km' => round(NearbyPlaces::km($lat, $lng, (float) $row['lat'], (float) $row['lng']), 3)])
            ->sortBy('km')
            ->take(3)
            ->map(fn (array $row) => [
                ...$row,
                'saved' => in_array($row['id'], $saved, true),
            ])
            ->values()
            ->all();
    }

    /**
     * Places' Around here rows as their listed hours read at `$at`'s
     * 5-minute slot, in the order given. Public listings only, the same for
     * everyone: kept by the place and the slot, with no distance (no point)
     * and nothing of the person.
     *
     * @param  list<int>  $ids
     * @return Collection<int, array<string, mixed>>
     */
    private function rowsAt(array $ids, CarbonImmutable $at): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $local = $at->setTimezone(DayProjection::TIMEZONE);
        $slot = $local->setTime($local->hour, intdiv($local->minute, 5) * 5);
        $key = fn (int $id) => sprintf('today-discovery:place-row:%d:%d', $slot->getTimestamp(), $id);
        $cached = collect(Cache::many(array_map($key, $ids)));
        $missing = array_values(array_filter($ids, fn (int $id) => ! is_array($cached->get($key($id)))));

        if ($missing !== []) {
            $fresh = $this->open->rows($missing, null, null, $slot)
                ->map(fn (array $row) => collect($row)->except(['saved', 'km'])->all())
                ->keyBy('id');
            Cache::putMany($fresh->mapWithKeys(fn (array $row, int $id) => [$key($id) => $row])->all(), self::PLACE_ROW_SECONDS);
            $cached = $cached->merge($fresh->mapWithKeys(fn (array $row, int $id) => [$key($id) => $row]));
        }

        return collect($ids)->map(fn (int $id) => $cached->get($key($id)))->filter(fn (mixed $row) => is_array($row))->values();
    }

    /**
     * A day's listings as Events presents them on `$now`'s day, soonest
     * first, each with its start, end (both as listed, unix seconds) and
     * whether a time is listed. Public listings only, nothing of the person:
     * a read is kept for everyone for five minutes.
     *
     * @return Collection<int, array{start: int, end: int|null, time_listed: bool, event: array<string, mixed>}>
     */
    private function listings(CarbonImmutable $now, CarbonImmutable $day, ?int $take = null): Collection
    {
        $key = sprintf('today-discovery:listings:%s:%s:%s', $now->format('Y-m-d'), $day->format('Y-m-d'), $take ?? 'all');

        return collect(Cache::remember($key, self::LISTINGS_SECONDS, fn () => Event::occurringBetween($day->startOfDay(), $day->endOfDay())
            ->sortBy(fn (array $occurrence) => $occurrence['starts_at']->getTimestamp())
            ->when($take !== null, fn (Collection $occurrences) => $occurrences->take($take))
            ->map(fn (array $occurrence) => [
                'start' => $occurrence['starts_at']->getTimestamp(),
                'end' => $occurrence['ends_at']?->getTimestamp(),
                'time_listed' => $this->presenter->timeListed($occurrence['starts_at'], null),
                'event' => $this->presenter->present($occurrence['event'], $occurrence['starts_at'], $occurrence['ends_at']),
            ])
            ->values()
            ->all()));
    }

    /**
     * Around the next plan with a place: one idea in the same area, and the
     * everyday places open there when the plan ends (or now, once it has).
     * The idea is the person's own saved idea first, as the mock's around()
     * has it: a saved place there, or a saved event there after the plan.
     * Then tonight's listings. The point and time stay in this request
     * (OpenNearby).
     *
     * @param  Collection<int, DayItem>  $active
     * @param  Collection<int, array<string, mixed>>  $events
     * @return array<string, mixed>|null
     */
    private function around(User $user, CarbonImmutable $now, Collection $active, Collection $events): ?array
    {
        $anchor = $active->first(fn (DayItem $item) => $item->routable() && $item->locationLabel !== null);
        if (! $anchor instanceof DayItem) {
            return null;
        }

        $at = $anchor->endAt !== null && $anchor->endAt->greaterThan($now) ? $anchor->endAt : $now;
        $area = mb_strtolower(trim((string) $anchor->locationLabel));
        $inArea = fn (?string $value) => mb_strtolower(trim((string) $value)) === $area;
        $fits = fn (array $event) => $inArea($event['venue']['veedel'] ?? null)
            && ($anchor->endAt === null || CarbonImmutable::parse($event['occurrence_start'])->greaterThanOrEqualTo($anchor->endAt));

        // A saved event counts only as one of tonight's eligible listings.
        $listed = $events->keyBy(fn (array $event) => "event:{$event['id']}:".CarbonImmutable::parse($event['occurrence_start'])->getTimestamp());
        $kept = $this->saved->for($user)
            ->filter(fn (array $item) => $item['available'])
            ->map(fn (array $item) => match ($item['type']) {
                'place' => $inArea($item['area']) ? ['place' => $item] : null,
                default => ($event = $listed->get($item['key'])) !== null && $fits($event) ? ['event' => $event] : null,
            })
            ->filter()
            ->first();

        $place = isset($kept['place']) ? [
            'id' => $kept['place']['id'],
            'title' => $kept['place']['title'],
            'area' => $kept['place']['area'],
        ] : null;
        $idea = $kept['event'] ?? ($place === null ? $events->first($fits) : null);

        $places = $this->places($user, (float) $anchor->lat, (float) $anchor->lng, $at);

        if ($idea === null && $place === null && $places === []) {
            return null;
        }

        return [
            'anchor' => [
                'id' => $anchor->id,
                'title' => $anchor->title,
                'area' => $anchor->locationLabel,
                'start' => $anchor->startAt?->format('H:i'),
                'end' => $anchor->endAt?->format('H:i'),
            ],
            'at' => $at->format('H:i'),
            'idea' => $idea,
            'place' => $place,
            'near' => null,
            'saved' => $kept !== null,
            'places' => $places,
        ];
    }
}
