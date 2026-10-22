<?php

namespace App\Composer\Assistant;

use App\Composer\Answers\EventAnswers;
use App\Composer\Candidate;
use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Enums\SpotCategory;
use App\Models\Event;
use App\Personalisation\AffinitySnapshot;
use App\Places\PlaceCapabilities;
use App\Services\WeatherService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * What Composer's assistant may look at (owner, 22 Oct 2026: "it should act as a proper
 * assistant"), as tools it calls: what's on, places to go and the weather, from the same
 * listings, catalogue and forecast the rest of the app shows. Read-only. The person's own
 * taste and day are tools only with their opt-in to personalisation (owner, 22 Oct 2026),
 * and then what they marked "not for me" stays out of the results. Every result carries
 * an id the answer must cite, and keeps the item behind it so the answer can show it as
 * the app draws it.
 */
final class AssistantTools
{
    /** Results per lookup. */
    private const SHOWN = 8;

    /** @var array<string, string> each result's text, by id, as the assistant read it */
    public array $read = [];

    /** @var array<string, array<string, mixed>> listings found, by id (an occurrence) */
    public array $events = [];

    /** @var array<string, Candidate> places found, by id */
    public array $places = [];

    public function __construct(
        private CarbonImmutable $now,
        private float $lat,
        private float $lng,
        private CandidateRepository $catalogue,
        private EventAnswers $listings,
        private WeatherService $weather,
        // With the opt-in only: what they like and skip (TasteFacts), their day (AroundNow::day),
        // and their affinities, whose hides keep "not for me" items out.
        private ?AffinitySnapshot $affinities = null,
        private ?array $taste = null,
        private array $day = [],
        // Where the page asks how far it's got ("composer:progress:…"), when it does.
        private ?string $progress = null,
        // What the person turned down in this conversation ("nope, something else"): event and
        // spot ids that no lookup returns again (owner's preview test, 22 Oct 2026).
        private array $exclude = ['event' => [], 'spot' => []],
    ) {}

    /** Whether the person's own taste and day are tools here (the opt-in). */
    public function personal(): bool
    {
        return $this->affinities !== null;
    }

    /**
     * What it's doing, for the page's "Looking at…" line: the step and what it has looked at
     * so far. Only what really happens.
     */
    public function progress(string $step): void
    {
        if ($this->progress !== null) {
            Cache::put($this->progress, ['step' => $step, 'looked' => $this->looked(), 'at' => now()->getTimestamp()], 60);
        }
    }

    /** @var list<string> the lookups made, by the page's names for them */
    private array $looked = [];

    /** @return list<string> what it looked at (listings, places, weather, plans, taste), once each */
    public function looked(): array
    {
        return array_values(array_unique($this->looked));
    }

    /**
     * @param  bool  $personal  with the opt-in: the person's taste and day too
     * @param  bool  $weather  whether there is weather to look at (without it, no weather tool, so no "the weather is unavailable")
     * @return list<array<string, mixed>> the tools, as the Messages API takes them
     */
    public static function definitions(bool $personal = false, bool $weather = true): array
    {
        $kinds = array_values(array_unique([...array_map(fn (SpotCategory $c): string => $c->value, SpotCategory::cases()), ...SpotCategory::placesCoarse()]));

        return [
            ['name' => 'search_events', 'description' => 'What is listed in Cologne on a day: concerts, exhibitions, talks, markets and more, ranked as Events ranks them. Still to come only, for today.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'day' => ['type' => 'string', 'description' => 'today, tomorrow or YYYY-MM-DD (within 7 days)'],
                    'from' => ['type' => 'string', 'description' => 'HH:MM, optional'],
                    'until' => ['type' => 'string', 'description' => 'HH:MM, optional'],
                    'topic' => ['type' => 'string', 'description' => 'optional words to match, e.g. jazz, kids, museum'],
                    'free' => ['type' => 'boolean', 'description' => 'only listings marked free'],
                ], 'required' => ['day']]],
            ['name' => 'search_places', 'description' => 'Places in Cologne from Expadu\'s catalogue (cafes, bars, museums, parks, sports places…), with verified hours where known.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'kind' => ['type' => 'string', 'enum' => $kinds],
                    'activity' => ['type' => 'string', 'enum' => PlaceCapabilities::ACTIVITIES],
                    'area' => ['type' => 'string', 'description' => 'a Cologne Veedel, optional'],
                    'mood' => ['type' => 'string', 'enum' => Constraints::MOODS],
                    'open_at' => ['type' => 'string', 'description' => 'HH:MM today, optional'],
                    'indoors' => ['type' => 'boolean', 'description' => 'only places indoors'],
                    'free' => ['type' => 'boolean', 'description' => 'only places that cost nothing'],
                ]]],
            ...($weather ? [['name' => 'weather', 'description' => 'Cologne\'s weather now and for the next hours.', 'input_schema' => ['type' => 'object', 'properties' => (object) []]]] : []),
            ...($personal ? [
                ['name' => 'my_taste', 'description' => 'What this person tends to like and skip (kinds of places and events, areas, times of day), from what they saved, opened and marked. They turned personalisation on.',
                    'input_schema' => ['type' => 'object', 'properties' => (object) []]],
                ['name' => 'my_day', 'description' => 'This person\'s own plans and appointments today and tomorrow. They turned personalisation on.',
                    'input_schema' => ['type' => 'object', 'properties' => (object) []]],
            ] : []),
        ];
    }

    /**
     * One lookup, as text the assistant reads: one line per result, each with its id.
     *
     * @param  array<string, mixed>  $input
     */
    public function run(string $name, array $input): string
    {
        $this->looked[] = match ($name) {
            'search_events' => 'listings', 'search_places' => 'places', 'my_day' => 'plans', 'my_taste' => 'taste', default => $name,
        };
        try {
            return match ($name) {
                'search_events' => $this->searchEvents($input),
                'search_places' => $this->searchPlaces($input),
                'weather' => $this->weatherNow(),
                'my_taste' => $this->myTaste(),
                'my_day' => $this->myDay(),
                default => 'Unknown tool.',
            };
        } catch (Throwable) {
            return 'Nothing found: the lookup failed.';
        }
    }

    private function searchEvents(array $input): string
    {
        $day = match ($input['day'] ?? 'today') {
            'today' => $this->now->startOfDay(),
            'tomorrow' => $this->now->startOfDay()->addDay(),
            default => CarbonImmutable::parse((string) $input['day'], 'Europe/Berlin')->startOfDay(),
        };
        if ($day->lessThan($this->now->startOfDay()) || $day->greaterThan($this->now->startOfDay()->addDays(7))) {
            return 'Only today and the next 7 days are listed.';
        }
        $from = $this->clock($input['from'] ?? null, $day) ?? $day;
        $until = $this->clock($input['until'] ?? null, $day) ?? $day->endOfDay();
        $from = $from->max($day->isSameDay($this->now) ? $this->now : $day);
        $occurrences = Event::occurringBetween($day, $day->endOfDay())
            ->filter(fn (array $o): bool => $o['starts_at']->lessThanOrEqualTo($until) && ($o['ends_at'] ?? $o['starts_at'])->greaterThanOrEqualTo($from));
        // What they marked "not for me" (with the opt-in) or turned down in this conversation stays out.
        $occurrences = $occurrences->reject(fn (array $o): bool => ($this->affinities?->isHidden('event', $o['event']->id) ?? false)
            || in_array($o['event']->id, $this->exclude['event'] ?? [], true));
        if (($input['free'] ?? false) === true) {
            $occurrences = $occurrences->filter(fn (array $o): bool => $o['event']->is_free || preg_match('/\b(free|frei|kostenlos|gratis)\b/iu', (string) $o['event']->price_text) === 1);
        }
        $topic = trim((string) ($input['topic'] ?? ''));
        if ($topic !== '') {
            $words = array_filter(preg_split('/\s+/u', mb_strtolower($topic)) ?: [], fn (string $w): bool => mb_strlen($w) >= 3);
            $occurrences = $occurrences->filter(fn (array $o): bool => $words === [] || collect($words)->contains(fn (string $w): bool => str_contains(
                mb_strtolower($o['event']->title.' '.$o['event']->title_en.' '.$o['event']->category.' '.$o['event']->description), $w)));
        }
        $shown = collect($this->listings->posters($occurrences->values(), self::SHOWN));
        if ($shown->isEmpty()) {
            return 'Nothing listed for that.';
        }

        return $shown->map(function (array $poster) use ($occurrences): string {
            $id = 'e'.$poster['id'].'@'.CarbonImmutable::parse($poster['occurrence_start'])->getTimestamp();
            $this->events[$id] = $occurrences->first(fn (array $o): bool => $o['event']->id === $poster['id']
                && $o['starts_at']->getTimestamp() === CarbonImmutable::parse($poster['occurrence_start'])->getTimestamp());

            return $this->line($id, self::eventText($poster));
        })->implode("\n");
    }

    private function searchPlaces(array $input): string
    {
        $at = $this->clock($input['open_at'] ?? null, $this->now) ?? $this->now;
        $found = collect($this->catalogue->candidatesFor(new Constraints(
            windowStart: $at,
            windowEnd: $at->addHours(2)->min($at->endOfDay()),
            areas: array_values(array_filter([is_string($input['area'] ?? null) ? $input['area'] : null])),
            categories: array_values(array_filter([is_string($input['kind'] ?? null) ? $input['kind'] : null])),
            activities: array_values(array_filter([is_string($input['activity'] ?? null) ? $input['activity'] : null])),
            moods: array_values(array_filter([is_string($input['mood'] ?? null) ? $input['mood'] : null])),
        ), $this->lat, $this->lng))
            ->filter(fn (Candidate $c): bool => $c->type === 'spot' && ! $c->closedToday
                && ! ($this->affinities?->isHidden('spot', (int) substr($c->id, 5)) ?? false)
                && ! in_array((int) substr($c->id, 5), $this->exclude['spot'] ?? [], true)
                && (($input['indoors'] ?? false) !== true || ! $c->outdoor)
                && (($input['free'] ?? false) !== true || $c->costTier === 'free')
                && ($c->hoursAssumed || $c->opensAt === null || ($c->opensAt->lessThanOrEqualTo($at) && $c->closesAt?->greaterThan($at))))
            ->sortBy(fn (Candidate $c): float => $c->distanceKmFromOrigin ?? INF)
            // Nearest first, but no more than two of one kind, so a second look finds something different.
            ->groupBy('category')->flatMap(fn ($kind) => $kind->take(2))
            ->sortBy(fn (Candidate $c): float => $c->distanceKmFromOrigin ?? INF)
            ->take(self::SHOWN)->values();
        if ($found->isEmpty()) {
            return 'No place listed for that.';
        }

        return $found->map(function (Candidate $c): string {
            $this->places[$c->id] = $c;

            return $this->line($c->id, self::placeText($c));
        })->implode("\n");
    }

    private function weatherNow(): string
    {
        $current = $this->weather->getCurrentWeather();
        $forecast = $this->weather->getForecast();
        if (($current['available'] ?? false) !== true) {
            return 'The weather is unavailable right now.';
        }
        $text = "Cologne now: {$current['temperature']}°".(is_string($current['condition'] ?? null) ? ", {$current['condition']}" : '')
            .(is_string($forecast['rain_summary'] ?? null) && $forecast['rain_summary'] !== 'No data' ? ". Next hours: {$forecast['rain_summary']}" : '');

        return $this->line('weather', $text);
    }

    /** What they like and skip, as TasteFacts states it: kinds, areas, times of day. */
    private function myTaste(): string
    {
        if (! $this->personal() || $this->taste === null) {
            return 'Not enough saved or opened yet to say what this person likes.';
        }
        $levels = ['strong' => 'likes a lot', 'some' => 'likes', 'avoid' => 'tends to skip'];
        $lines = [];
        foreach ($this->taste['categories'] as $i => $row) {
            $lines[] = $this->line('t'.($i + 1), "{$levels[$row['level']]} {$row['name']}");
        }
        foreach ($this->taste['veedels'] as $i => $row) {
            $lines[] = $this->line('a'.($i + 1), ($row['level'] === 'avoid' ? 'rarely goes to ' : 'goes to ')."{$row['name']}".($row['level'] === 'strong' ? ' a lot' : ''));
        }
        if ($this->taste['dayparts'] !== []) {
            $lines[] = $this->line('h1', 'usually out in the '.implode(' and ', $this->taste['dayparts']));
        }
        if ($this->taste['week'] !== null) {
            $lines[] = $this->line('w1', $this->taste['week'] === 'weekend' ? 'goes out more at weekends' : 'goes out more on weekdays');
        }

        return implode("\n", $lines);
    }

    /** Their plans and appointments today and tomorrow, as their records hold them. */
    private function myDay(): string
    {
        if (! $this->personal()) {
            return 'Not available.';
        }
        if ($this->day === []) {
            // A fact like any other: "you have nothing planned" cites it.
            return $this->line('d0', 'nothing planned today or tomorrow');
        }

        return collect($this->day)->values()->map(fn (array $fact, int $i): string => $this->line('d'.($i + 1), "{$fact['label']}: {$fact['value']}"))->implode("\n");
    }

    /** A listing as the assistant reads it: title, kind, venue, time and price, as listed. */
    public static function eventText(array $poster): string
    {
        $start = CarbonImmutable::parse($poster['occurrence_start'])->setTimezone('Europe/Berlin');
        $end = $poster['occurrence_end'] !== null ? CarbonImmutable::parse($poster['occurrence_end'])->setTimezone('Europe/Berlin') : null;
        $when = ! $poster['time_listed'] ? $start->format('l j F').', time not listed'
            : $start->format('l j F H:i').($end !== null && $end->isSameDay($start) ? '–'.$end->format('H:i') : '');
        $venue = trim(($poster['venue']['name'] ?? '').(filled($poster['venue']['veedel'] ?? null) ? ", {$poster['venue']['veedel']}" : ''), ', ');

        return "\"{$poster['title']}\" ({$poster['category']})".($venue !== '' ? " at {$venue}" : '').". {$when}."
            .(filled($poster['price_text'] ?? null) ? " Price: {$poster['price_text']}." : '');
    }

    /** A place as the assistant reads it: name, kind, Veedel, its verified hours and its listed character. */
    public static function placeText(Candidate $c): string
    {
        $kind = SpotCategory::tryFrom($c->category)?->label() ?? ucfirst(str_replace('_', ' ', $c->category));

        return "\"{$c->name}\" ({$kind}".($c->outdoor ? ', outdoors' : ', indoors').')'.($c->veedel !== null ? " in {$c->veedel}" : '').'.'
            .(! $c->hoursAssumed && $c->closesAt !== null ? " Open until {$c->closesAt->setTimezone('Europe/Berlin')->format('H:i')} today." : '')
            .($c->costTier === 'free' ? ' Free.' : '')
            .($c->vibeTags !== [] ? ' Listed as '.implode(', ', array_slice($c->vibeTags, 0, 3)).'.' : '');
    }

    private function line(string $id, string $text): string
    {
        $this->read[$id] = $text;

        return "[{$id}] {$text}";
    }

    private function clock(mixed $value, CarbonImmutable $day): ?CarbonImmutable
    {
        return is_string($value) && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value) === 1 ? $day->setTimeFromTimeString($value) : null;
    }

    /** @return Collection<int, array<string, mixed>> the listings chosen, as occurrences */
    public function occurrences(array $ids): Collection
    {
        return collect($ids)->map(fn (mixed $id): ?array => is_string($id) ? ($this->events[$id] ?? null) : null)->filter()->values();
    }
}
