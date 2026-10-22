<?php

namespace App\Composer\Assistant;

use App\Bureaucracy\RuleSourcePolicy;
use App\Composer\Answers\Destinations;
use App\Composer\Answers\EventAnswers;
use App\Composer\Answers\TripAnswers;
use App\Composer\Candidate;
use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Enums\LocationSource;
use App\Enums\SpotCategory;
use App\Models\Event;
use App\Models\Task;
use App\Models\User;
use App\Personalisation\AffinitySnapshot;
use App\Places\PlaceCapabilities;
use App\Services\DisruptionService;
use App\Services\KvbApiService;
use App\Services\LocationContext;
use App\Services\NearbyStopService;
use App\Services\UserLocationService;
use App\Services\WeatherService;
use App\Transit\NearbyStation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Everything Composer's assistant can look at and do (owner, 22 Oct 2026: one assistant for
 * every message, "a new capability is a new tool"), as tools it calls: where the person is,
 * departures, the way to a place, KVB notices, what's on, places, the weather, the planner,
 * reviewed paperwork guides, their saved places. Read-only, from the same data the rest of
 * the app shows. Every result has an id the answer cites, and keeps what's behind it, so the
 * answer can show it as the app draws it (`cards`, `events`, `places`).
 *
 * What's the person's own stays theirs: their Paperwork, their trip and their next plan
 * are cards the AI may show but never reads (OwnCards); their taste and day are tools only
 * with their opt-in to personalisation, and then what they marked "not for me" stays out.
 * Where they are reaches the AI as their nearest stop and Veedel, never coordinates.
 */
final class AssistantTools
{
    /** Results per lookup. */
    private const SHOWN = 8;

    /** KVB notices per lookup. */
    private const NOTICES = 3;

    /** Ids whose facts are about the person (their place, trip, records, taste, day): a sentence citing one may speak of them. */
    public const PERSONAL_IDS = '/^((t|a|h|w|d|m|r|c|b|plan)\d+|here|pw|trip|next)$/';

    /** @var array<string, string> each result's text, by id, as the assistant read it */
    public array $read = [];

    /** @var array<string, array<string, mixed>> listings found, by id (an occurrence) */
    public array $events = [];

    /** @var array<string, Candidate> places found, by id */
    public array $places = [];

    /** @var array<string, array<string, mixed>> what else it can show, by id: Composer's own blocks (a trip, a board, a plan, a card) */
    public array $cards = [];

    /** @var list<array{0: string, 1: int}> each lookup and its milliseconds, in order (composer:eval reads where the time goes) */
    public array $timings = [];

    /** @var list<string> the lookups made, by the page's names for them */
    private array $looked = [];

    /** @var array{name: string, lines: list<string>, walk_min: int}|false|null the nearest stop, once looked up */
    private array|false|null $stop = null;

    private int $count = 0;

    public function __construct(
        private User $user,
        private CarbonImmutable $now,
        // Where the person is or starts from (UserLocationService::context); none when unknown.
        private LocationContext $location,
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
        // What this conversation suggested and named: events and spots it showed (left out when
        // the person turns them down), and the places and areas of its trips (which Cafe Nova).
        private array $earlier = ['event' => [], 'spot' => [], 'areas' => [], 'places' => []],
        // Their Composer plans, by date (their next plan, from code only).
        private array $plans = [],
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

    /** @return list<string> what it looked at (listings, places, weather, departures, trip, plan…), once each */
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
        $none = ['type' => 'object', 'properties' => (object) []];

        return [
            ['name' => 'where_am_i', 'description' => 'Where the person is: their nearest KVB stop and Veedel, from their browser\'s location or the start they chose, and how that\'s known.', 'input_schema' => $none],
            ['name' => 'departures', 'description' => 'Live departures at a KVB stop, by line and direction. Without a stop: at the stop nearest the person.',
                'input_schema' => ['type' => 'object', 'properties' => ['stop' => ['type' => 'string', 'description' => 'a KVB stop name, optional']]]],
            ['name' => 'plan_trip', 'description' => 'The way to a place by bus, tram and train from where the person is, with Expadu\'s place finder. When the name fits several places, it says so and you ask which (show the choice). Also answers "how far is it?".',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'to' => ['type' => 'string', 'description' => 'the place as they named it, e.g. Cafe Nova, Hauptbahnhof, Rheinpark'],
                    'area' => ['type' => 'string', 'description' => 'a Veedel they named with it, optional'],
                    'at' => ['type' => 'string', 'description' => 'HH:MM today when they want to leave, optional'],
                    'how_far' => ['type' => 'boolean', 'description' => 'they asked how far it is'],
                ], 'required' => ['to']]],
            ['name' => 'notices', 'description' => 'KVB service notices (disruptions, diversions, works) on lines. Without lines: on the lines at the person\'s nearest stop.',
                'input_schema' => ['type' => 'object', 'properties' => ['lines' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'line numbers, e.g. 1, 18, 136']]]],
            ['name' => 'search_events', 'description' => 'What is listed in Cologne on a day: concerts, exhibitions, talks, markets and more, ranked as Events ranks them. Still to come only, for today.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'day' => ['type' => 'string', 'description' => 'today, tomorrow or YYYY-MM-DD (within 7 days)'],
                    'from' => ['type' => 'string', 'description' => 'HH:MM, optional'],
                    'until' => ['type' => 'string', 'description' => 'HH:MM, optional'],
                    'topic' => ['type' => 'string', 'description' => 'optional words to match, e.g. jazz, kids, museum'],
                    'free' => ['type' => 'boolean', 'description' => 'only listings marked free'],
                    'leave_out_earlier' => ['type' => 'boolean', 'description' => 'leave out what you suggested earlier in this conversation (they turned it down)'],
                ], 'required' => ['day']]],
            ['name' => 'search_places', 'description' => 'Places in Cologne from Expadu\'s catalogue (cafes, bars, museums, parks, sports places…), with verified hours where known, nearest to the person first.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'kind' => ['type' => 'string', 'enum' => $kinds],
                    'activity' => ['type' => 'string', 'enum' => PlaceCapabilities::ACTIVITIES],
                    'area' => ['type' => 'string', 'description' => 'a Cologne Veedel, optional'],
                    'mood' => ['type' => 'string', 'enum' => Constraints::MOODS],
                    'open_at' => ['type' => 'string', 'description' => 'HH:MM today, optional'],
                    'indoors' => ['type' => 'boolean', 'description' => 'only places indoors'],
                    'free' => ['type' => 'boolean', 'description' => 'only places that cost nothing'],
                    'leave_out_earlier' => ['type' => 'boolean', 'description' => 'leave out what you suggested earlier in this conversation (they turned it down)'],
                ]]],
            ...($weather ? [['name' => 'weather', 'description' => 'Cologne\'s weather now and for the next hours.', 'input_schema' => $none]] : []),
            PlanDay::definition(),
            ['name' => 'guidance', 'description' => 'Expadu\'s reviewed guides to German paperwork (registration, residence permits, tax ID, insurance…) matching some words: their titles. Paperwork and legal questions are answered only by these guides, never by you.',
                'input_schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'a few words, e.g. Anmeldung, blue card, tax ID']], 'required' => ['query']]],
            ['name' => 'paperwork', 'description' => 'Shows the person their own Paperwork record as a card: their checklist for the next step and their next date. You don\'t get its contents.', 'input_schema' => $none],
            ['name' => 'my_trip', 'description' => 'Shows the person their trip as a card: the trip under way, else the way to their next appointment today (when to leave, their tram, where to get off). You don\'t get its contents.',
                'input_schema' => ['type' => 'object', 'properties' => ['ask' => ['type' => 'string', 'enum' => ['leave', 'status', 'stop', 'arrive', 'next'], 'description' => 'what they asked: when to leave, whether it runs on time, where to get off, when they arrive, what\'s next']]]],
            ['name' => 'my_next', 'description' => 'Shows the person their next plan or appointment as a card. You don\'t get its contents.',
                'input_schema' => ['type' => 'object', 'properties' => ['appointment' => ['type' => 'boolean', 'description' => 'they asked about appointments only']]]],
            ['name' => 'my_places', 'description' => 'The person\'s saved places (Home, Work and others) by name and Veedel.', 'input_schema' => $none],
            ...($personal ? [
                ['name' => 'my_taste', 'description' => 'What this person tends to like and skip (kinds of places and events, areas, times of day), from what they saved, opened and marked. They turned personalisation on.', 'input_schema' => $none],
                ['name' => 'my_day', 'description' => 'This person\'s own plans and appointments today and tomorrow. They turned personalisation on.', 'input_schema' => $none],
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
            'search_events' => 'listings', 'search_places' => 'places', 'my_day' => 'plans', 'my_taste' => 'taste',
            'where_am_i' => 'location', 'plan_trip' => 'trip', 'plan_day' => 'planner', 'my_trip' => 'your_trip', 'my_next' => 'your_plans', default => $name,
        };
        $started = hrtime(true);
        try {
            return match ($name) {
                'where_am_i' => $this->whereAmI(),
                'departures' => $this->departures($input),
                'plan_trip' => $this->planTrip($input),
                'notices' => $this->notices($input),
                'search_events' => $this->searchEvents($input),
                'search_places' => $this->searchPlaces($input),
                'weather' => $this->weatherNow(),
                'plan_day' => $this->planDay($input),
                'guidance' => $this->guidance($input),
                'paperwork' => $this->own('pw', app(OwnCards::class)->paperwork($this->user, $this->now), 'their Paperwork record'),
                'my_trip' => $this->own('trip', app(OwnCards::class)->trip($this->user, in_array($input['ask'] ?? null, ['leave', 'status', 'stop', 'arrive', 'next'], true) ? $input['ask'] : 'leave', $this->now), 'their trip'),
                'my_next' => $this->own('next', app(OwnCards::class)->next($this->user, $this->plans, ($input['appointment'] ?? false) === true, $this->now), 'their next plan or appointment'),
                'my_places' => $this->myPlaces(),
                'my_taste' => $this->myTaste(),
                'my_day' => $this->myDay(),
                default => 'Unknown tool.',
            };
        } catch (Throwable) {
            return 'Nothing found: the lookup failed.';
        } finally {
            $this->timings[] = [$name, (int) round((hrtime(true) - $started) / 1e6)];
        }
    }

    /** Where they are: their nearest stop and Veedel, and how that's known (never coordinates). */
    private function whereAmI(): string
    {
        if (! $this->location->hasOrigin()) {
            return $this->line('here', 'Where they are isn\'t known: no location shared, no start chosen and no home Veedel set.');
        }
        $from = $this->from();
        $area = app(UserLocationService::class)->veedelAt((float) $this->location->lat, (float) $this->location->lng);
        // A Veedel's middle isn't where they are: no stop is named from it.
        $stop = $this->location->source === LocationSource::Area ? null : $this->nearestStop();
        $this->cards['here'] = array_filter(['type' => 'where', 'area' => $area, 'from' => $from,
            'stop' => $stop === null ? null : ['name' => $stop['name'], 'walk_min' => $stop['walk_min'], 'lines' => $stop['lines']]], fn ($v) => $v !== null);

        return $this->line('here', ucfirst($from).': '.($area !== null ? "in {$area}" : 'outside the Veedel Expadu knows')
            .($stop !== null ? ". Nearest KVB stop: {$stop['name']}, {$stop['walk_min']} min walk (lines ".implode(', ', $stop['lines']).')' : '').'.');
    }

    /** How where they are is known, in words the AI may pass on. */
    private function from(): string
    {
        $place = request()->input('from_place');

        return match ($this->location->source) {
            LocationSource::Live => is_numeric($place) ? "from the start they chose, their saved place \"{$this->location->label}\""
                : ($this->location->label === 'Your location' ? 'from their browser\'s location' : 'from the start they chose'),
            LocationSource::Confirmed => 'from where they confirmed they are',
            LocationSource::Ping => 'from their last shared location',
            LocationSource::Area => request()->input('from_area') === $this->location->label
                ? "from the area they chose ({$this->location->label}), not their exact position"
                : "from their home Veedel ({$this->location->label}): their position now isn't known",
            LocationSource::None => 'unknown',
        };
    }

    /** @return array{name: string, lines: list<string>, walk_min: int}|null the KVB stop nearest them, within a walk */
    private function nearestStop(): ?array
    {
        if ($this->stop === null) {
            $stop = $this->location->hasOrigin() ? (app(NearbyStopService::class)->getWalkableStops((float) $this->location->lat, (float) $this->location->lng, 1200)[0] ?? null) : null;
            $this->stop = $stop === null ? false : ['name' => (string) $stop['name'], 'lines' => array_map('strval', $stop['lines']), 'walk_min' => (int) $stop['walk_min']];
        }

        return $this->stop ?: null;
    }

    /** Live departures at a stop (Travel's board), by line and direction. */
    private function departures(array $input): string
    {
        $named = trim((string) ($input['stop'] ?? ''));
        $name = $named !== '' ? $this->stopNamed($named) : $this->nearestStop()['name'] ?? null;
        if ($name === null) {
            return $named !== '' ? "No KVB stop is called \"{$named}\"." : 'Where they are isn\'t known, so there\'s no nearest stop. Ask which stop.';
        }
        $board = app(NearbyStation::class)->named($name);
        if ($board === null || ($board['lines'] ?? []) === []) {
            return "No departures are known at {$name} right now.";
        }
        $id = 'b'.(++$this->count);
        $basis = ['realtime' => 'live times', 'scheduled' => 'timetable times, not live', 'stale' => 'times from a few minutes ago'][$board['time_basis']] ?? 'times unavailable';
        $rows = [];
        foreach ($board['lines'] as $line) {
            foreach ($line['dirs'] as $dir) {
                $times = collect([$dir['next'] ?? null, ...($dir['later'] ?? [])])->filter()->take(3)
                    ->map(fn (array $d): string => $d['time'].(($d['cancelled'] ?? false) ? ' cancelled' : (($d['delay'] ?? 0) > 0 ? " ({$d['delay']} min delay)" : '')))->implode(', ');
                if ($times !== '') {
                    $rows[] = "  {$line['line']} to {$dir['dest']}: {$times}";
                }
            }
        }
        $this->cards[$id] = ['type' => 'departures', 'board' => array_intersect_key($board, array_flip(['name', 'station', 'time_basis', 'is_stale', 'observed_at', 'lines', 'lifts', 'notices']))];

        return $this->line($id, "Departures at {$name} ({$basis}):\n".implode("\n", array_slice($rows, 0, 12)));
    }

    /** The KVB stop a name means: as KVB writes it, else the closest spelling. */
    private function stopNamed(string $named): ?string
    {
        $key = fn (string $name): string => preg_replace('/[^a-z0-9]/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name));
        $wanted = $key($named);
        $names = collect(app(KvbApiService::class)->getStops())->pluck('name')->unique();

        return $names->first(fn (string $name): bool => $key($name) === $wanted)
            ?? $names->filter(fn (string $name): bool => $wanted !== '' && str_starts_with($key($name), $wanted))->sortBy(fn (string $name): int => strlen($name))->first()
            ?? $names->filter(fn (string $name): bool => $wanted !== '' && str_contains($key($name), $wanted))->sortBy(fn (string $name): int => strlen($name))->first();
    }

    /** The way to a place (TripAnswers), or which of several places of that name. */
    private function planTrip(array $input): string
    {
        $to = trim((string) ($input['to'] ?? ''));
        $area = is_string($input['area'] ?? null) && trim($input['area']) !== '' ? trim($input['area']) : null;
        // "Café Nova in Deutz" (a tapped choice, copied as is) is Café Nova, in Deutz.
        if ($area === null && preg_match('/^(.+?)\s+in\s+(.+)$/iu', $to, $m) === 1 && PlanDay::areas([$m[2]]) !== []) {
            [$to, $area] = [trim($m[1]), PlanDay::areas([$m[2]])[0]];
        }
        // "How far is it?" is about the place this conversation's last trip went to, by its name and
        // area. Asked for by name again, the place finder picks the one meant before and offers the others.
        $key = fn (string $name): string => Destinations::key($name);
        $before = ($input['how_far'] ?? false) === true
            ? collect($this->earlier['places'] ?? [])->first(fn (array $p): bool => $key($p['name']) === $key($to) && ($area === null || $p['area'] === $area)) : null;
        $area ??= $before['area'] ?? null;
        $answer = app(TripAnswers::class)->toPlace($this->user, [
            'place' => $to,
            'area' => $area,
            'at' => is_string($input['at'] ?? null) && preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $input['at']) === 1 ? $input['at'] : null,
            'ask' => ($input['how_far'] ?? false) === true ? 'distance' : null,
            // Places this conversation already named, so "Cafe Nova" can mean the one meant before.
            'mentioned' => ['spots' => $this->earlier['spot'] === [] ? [] : array_keys($this->earlier['spot']), 'areas' => $this->earlier['areas'] ?? []],
        ], $this->now, $this->location);
        $extra = $answer['extra'];
        // Several places of one name and none known: the question, with a tap for each.
        if (! isset($extra['trip']) && count($extra['suggestions'] ?? []) > 1 && str_ends_with($answer['text'], '?')) {
            $id = 'c'.(++$this->count);
            $this->cards[$id] = ['type' => 'choices', 'text' => $answer['text'], 'options' => $extra['suggestions']];

            return $this->line($id, $answer['text'].' The places: '.collect($extra['suggestions'])->pluck('label')->implode('; ').'.');
        }
        $id = 'r'.(++$this->count);
        $this->cards[$id] = [...array_diff_key($extra, ['also' => true]), 'type' => isset($extra['trip']) ? 'trip' : 'note', 'text' => $answer['text']];
        // Planned to the one this conversation meant: "There's also one in Altstadt-Nord", with its
        // tap, is Composer's own line, said whatever the AI says, and shown with the trip.
        if (isset($extra['also'])) {
            $this->cards[$id.'-also'] = ['type' => 'choices', 'text' => $extra['also']['text'], 'options' => $extra['also']['options'], 'private' => true];
        }

        // The place it's to, by name, so the answer may name it ("Café Nova in Deutz is about 4.4 km away").
        $to = isset($extra['destination']['name']) ? "Trip to \"{$extra['destination']['name']}\"".(isset($extra['destination']['area']) ? " in {$extra['destination']['area']}" : '').': ' : '';

        return $this->line($id, $to.trim(($extra['title'] ?? '').' '.$answer['text']));
    }

    /** KVB notices on the lines named, or on the lines at their nearest stop; public notices only. */
    private function notices(array $input): string
    {
        $lines = array_values(array_filter(array_map(fn (mixed $l): string => trim((string) $l), is_array($input['lines'] ?? null) ? $input['lines'] : [])));
        $lines = $lines !== [] ? $lines : ($this->nearestStop()['lines'] ?? []);
        if ($lines === []) {
            return 'Which lines? Where they are isn\'t known, so there\'s no nearest stop.';
        }
        $found = collect(app(DisruptionService::class)->getLineDisruptions())
            ->map(fn (array $notice): array => [...$notice, 'on' => array_values(array_intersect(array_map('strval', $notice['affected_lines'] ?? []), $lines))])
            ->filter(fn (array $notice): bool => $notice['on'] !== [])->take(self::NOTICES)->values();
        if ($found->isEmpty()) {
            return 'No KVB notices on lines '.implode(', ', $lines).' right now.';
        }

        return $found->map(function (array $notice): string {
            $id = 'k'.(++$this->count);
            $label = 'KVB '.implode(', ', $notice['on']);
            $text = (string) ($notice['brief']['headline'] ?? $notice['title']);
            $this->cards[$id] = array_filter(['type' => 'facts', 'facts' => [['label' => $label, 'value' => $text, 'known' => true]],
                'sources' => $notice['url'] !== null ? [['label' => 'KVB notice', 'href' => $notice['url']]] : null], fn ($v) => $v !== null);

            return $this->line($id, "{$label}: {$text}");
        })->implode("\n");
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
        $leaveOut = ($input['leave_out_earlier'] ?? false) === true ? array_keys($this->earlier['event']) : [];
        $occurrences = $occurrences->reject(fn (array $o): bool => ($this->affinities?->isHidden('event', $o['event']->id) ?? false)
            || in_array($o['event']->id, $leaveOut, true));
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
            // What was looked for, so "nothing free is on" is a fact the answer may state.
            return 'Nothing'.(($input['free'] ?? false) === true ? ' free' : '').' is listed '.($day->isSameDay($this->now) ? 'today' : 'on '.$day->format('l j F'))
                .($topic !== '' ? " for \"{$topic}\"" : '').(isset($input['from']) || isset($input['until']) ? ' at that time' : '').'.';
        }

        return $shown->map(function (array $poster) use ($occurrences): string {
            $id = 'e'.$poster['id'].'@'.CarbonImmutable::parse($poster['occurrence_start'])->getTimestamp();
            $this->events[$id] = $occurrences->first(fn (array $o): bool => $o['event']->id === $poster['id']
                && $o['starts_at']->getTimestamp() === CarbonImmutable::parse($poster['occurrence_start'])->getTimestamp());

            return $this->line($id, self::eventText($poster).(isset($this->earlier['event'][$poster['id']]) ? ' (You suggested it earlier.)' : ''));
        })->implode("\n");
    }

    private function searchPlaces(array $input): string
    {
        $at = $this->clock($input['open_at'] ?? null, $this->now) ?? $this->now;
        $area = is_string($input['area'] ?? null) && trim($input['area']) !== '' ? (PlanDay::areas([$input['area']])[0] ?? null) : null;
        if ($area === null && is_string($input['area'] ?? null) && trim($input['area']) !== '') {
            return "No Veedel is called \"{$input['area']}\". Places are found by Cologne's Veedel (e.g. Neustadt-Süd for the Südstadt).";
        }
        $leaveOut = ($input['leave_out_earlier'] ?? false) === true ? array_keys($this->earlier['spot']) : [];
        [$lat, $lng] = $this->location->hasOrigin() ? [(float) $this->location->lat, (float) $this->location->lng] : [CandidateRepository::COLOGNE_LAT, CandidateRepository::COLOGNE_LNG];
        // The nearest that fit, two of a kind, and a few spare for the filters below.
        $found = collect($this->catalogue->nearestSpots(new Constraints(
            windowStart: $at,
            windowEnd: $at->addHours(2)->min($at->endOfDay()),
            areas: array_values(array_filter([$area])),
            categories: array_values(array_filter([is_string($input['kind'] ?? null) ? $input['kind'] : null])),
            activities: array_values(array_filter([is_string($input['activity'] ?? null) ? $input['activity'] : null])),
            moods: array_values(array_filter([is_string($input['mood'] ?? null) ? $input['mood'] : null])),
        ), $lat, $lng, 2, self::SHOWN * 3))
            ->filter(fn (Candidate $c): bool => ! $c->closedToday
                && ! ($this->affinities?->isHidden('spot', (int) substr($c->id, 5)) ?? false)
                && ! in_array((int) substr($c->id, 5), $leaveOut, true)
                && (($input['indoors'] ?? false) !== true || ! $c->outdoor)
                && (($input['free'] ?? false) !== true || $c->costTier === 'free')
                && ($c->hoursAssumed || $c->opensAt === null || ($c->opensAt->lessThanOrEqualTo($at) && $c->closesAt?->greaterThan($at))))
            ->sortBy(fn (Candidate $c): float => $c->distanceKmFromOrigin ?? INF)
            // Nearest first, but no more than two of one kind, so a second look finds something different.
            ->groupBy('category')->flatMap(fn ($kind) => $kind->take(2))
            ->sortBy(fn (Candidate $c): float => $c->distanceKmFromOrigin ?? INF)
            ->take(self::SHOWN)->values();
        if ($found->isEmpty()) {
            return 'No'.(($input['free'] ?? false) === true ? ' free' : '').(($input['indoors'] ?? false) === true ? ' indoor' : '').' place is listed for that'
                .($area !== null ? " in {$area}" : '').'.';
        }

        // Nearest first ("closer to me" means the first ones), but no distances: where they are stays here.
        return ($area !== null ? "In {$area}".(mb_strtolower(trim($input['area'])) !== mb_strtolower($area) ? " (\"{$input['area']}\")" : '').', ' : '')
            .($this->location->hasOrigin() ? "nearest to the person first:\n" : "across Cologne (where they are isn't known):\n").$found->map(function (Candidate $c): string {
                $this->places[$c->id] = $c;

                return $this->line($c->id, self::placeText($c).(isset($this->earlier['spot'][(int) substr($c->id, 5)]) ? ' (You suggested it earlier.)' : ''));
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

    /** The planner's plan for what they asked (PlanDay), kept to show and add. */
    private function planDay(array $input): string
    {
        $planned = app(PlanDay::class)->plan($this->user, $input, $this->now);
        if (is_string($planned)) {
            return $planned;
        }
        $id = 'plan'.(++$this->count);
        $this->cards[$id] = ['type' => 'plan', 'token' => $planned['token'],
            'date' => CarbonImmutable::parse($planned['plan']['constraints']['window_start'])->setTimezone('Europe/Berlin')->toDateString()];

        // The areas asked for, as they said them ("Südstadt") and as the planner knows them.
        $asked = collect(is_array($input['areas'] ?? null) ? $input['areas'] : [])->filter(fn (mixed $a): bool => is_string($a))
            ->map(fn (string $a): string => ($k = PlanDay::areas([$a])[0] ?? $a) === $a ? $a : "{$k} (\"{$a}\")")->implode(', ');

        return $this->line($id, ($asked !== '' ? "Asked for: {$asked}.\n" : '').PlanDay::text($planned['plan']));
    }

    /** Reviewed paperwork guides matching the words: their titles, as Composer's guidance lists them. */
    private function guidance(array $input): string
    {
        $query = mb_substr(trim((string) ($input['query'] ?? '')), 0, 120);
        $words = array_filter(preg_split('/\W+/u', mb_strtolower($query)) ?: [], fn (string $word): bool => mb_strlen($word) > 3);
        $policy = app(RuleSourcePolicy::class);
        $guides = Task::query()->authoritative()->orderBy('key')->get()
            ->filter(fn (Task $task): bool => $policy->persistedErrors($task) === [])
            ->filter(fn (Task $task): bool => $words === [] || collect($words)->contains(fn (string $word): bool => str_contains(mb_strtolower($task->title.' '.$task->key), $word)))
            ->unique('title')->take(3)->values();
        if ($guides->isEmpty()) {
            return 'No reviewed guide matches that. Paperwork works out the steps from their own situation.';
        }
        $id = 'g'.(++$this->count);
        $this->cards[$id] = ['type' => 'guidance', 'query' => $query];

        return $this->line($id, 'Reviewed guides (shown as cards): '.$guides->map(fn (Task $task): string => "\"{$task->title}\"")->implode(', ').'.');
    }

    /** A card of their own the AI may show but never reads: only that it's there. */
    private function own(string $id, array $card, string $what): string
    {
        // Its words are Composer's and the AI never read them: the answer always says them.
        $this->cards[$id] = [...$card, 'private' => true];

        return $this->line($id, ucfirst($what).', shown to them as a card. Its contents stay private: say only that it\'s below.');
    }

    /** Their saved places, by name and Veedel, never where exactly. */
    private function myPlaces(): string
    {
        $places = $this->user->places()->located()->get(['id', 'name', 'category', 'lat', 'lng']);
        if ($places->isEmpty()) {
            return 'They have no saved places.';
        }
        $locations = app(UserLocationService::class);

        return $places->values()->map(fn ($place, int $i): string => $this->line('m'.($i + 1),
            ($place->category === 'custom' ? '' : ucfirst((string) $place->category).': ')."\"{$place->name}\" in ".($locations->veedelAt((float) $place->lat, (float) $place->lng) ?? 'an unknown Veedel')))->implode("\n");
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
