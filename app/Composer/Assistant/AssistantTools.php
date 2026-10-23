<?php

namespace App\Composer\Assistant;

use App\Bureaucracy\Facts\FactRegistry;
use App\Bureaucracy\RuleSourcePolicy;
use App\Composer\Actions\ActionRefused;
use App\Composer\Actions\ComposerActions;
use App\Composer\Actions\InAppPlaces;
use App\Composer\Actions\PaperworkActions;
use App\Composer\Actions\PlaceFeedbackAction;
use App\Composer\Answers\Destinations;
use App\Composer\Answers\EventAnswers;
use App\Composer\Answers\TripAnswers;
use App\Composer\Candidate;
use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Enums\LocationSource;
use App\Enums\SpotCategory;
use App\Models\BureaucracyPerson;
use App\Models\Event;
use App\Models\Spot;
use App\Models\Task;
use App\Models\User;
use App\Personalisation\AffinitySnapshot;
use App\Places\DestinationGrouping;
use App\Places\FacilityGroups;
use App\Places\OpeningAnswer;
use App\Places\PlaceAmenities;
use App\Places\PlaceCapabilities;
use App\Places\PlaceDisplayName;
use App\Places\PlaceWorkAnswers;
use App\Privacy\ProcessingPurpose;
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
use Illuminate\Support\Facades\DB;
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
    public const PERSONAL_IDS = '/^((t|a|h|w|d|m|r|c|b|x|o|plan)\d+|here|pw|trip|next)$/';

    /**
     * What each tool's result sends, as Composer's notice names it (ProcessingPurpose::WORDS).
     * A tool without one can't be sent: ComposerPaperworkNoticeTest fails on it.
     */
    public const CATEGORIES = [
        'where_am_i' => 'your_nearest_stop_and_veedel', 'departures' => 'departures', 'plan_trip' => 'trips_to_places_you_name',
        'notices' => 'transit_notices', 'search_events' => 'events_listed', 'search_places' => 'places_listed', 'weather' => 'weather',
        'plan_day' => 'plans_composed', 'guidance' => 'paperwork_guide_titles', 'my_places' => 'your_saved_places_names',
        'paperwork' => 'notes_that_your_own_cards_are_shown', 'my_trip' => 'notes_that_your_own_cards_are_shown',
        'my_next' => 'notes_that_your_own_cards_are_shown', 'open_in_app' => 'notes_that_your_own_cards_are_shown',
        'my_taste' => 'your_taste_if_personalised', 'my_day' => 'your_plans_if_personalised',
        'place_feedback' => 'changes_you_ask_for', 'set_my_place' => 'changes_you_ask_for', 'add_to_day' => 'changes_you_ask_for',
        'remove_from_day' => 'changes_you_ask_for', 'swap_in_day' => 'changes_you_ask_for', 'clear_day' => 'changes_you_ask_for',
        // Without their Paperwork permission, these only say it's needed.
        'change_paperwork_fact' => 'changes_you_ask_for', 'paperwork_step' => 'changes_you_ask_for', 'paperwork_report' => 'changes_you_ask_for',
    ];

    /** With their Paperwork permission for the message (composer_paperwork), these tools send their record. */
    public const PAPERWORK_CATEGORIES = [
        'paperwork' => 'your_paperwork_answers_and_tasks', 'change_paperwork_fact' => 'paperwork_changes_you_ask_for',
        'paperwork_step' => 'paperwork_changes_you_ask_for', 'paperwork_report' => 'paperwork_changes_you_ask_for',
    ];

    /**
     * Everything a request can carry: the message's own parts (Assistant::CONTEXT) and what each
     * tool's result is.
     *
     * @return list<string>
     */
    public static function categories(bool $paperwork): array
    {
        return array_values(array_unique([...array_values(Assistant::CONTEXT), ...array_values(self::CATEGORIES),
            ...($paperwork ? array_values(self::PAPERWORK_CATEGORIES) : [])]));
    }

    /** @var array<string, string> each result's text, by id, as the assistant read it */
    public array $read = [];

    /** @var array<string, array<string, mixed>> listings found, by id (an occurrence) */
    public array $events = [];

    /** @var array<string, Candidate> places found, by id */
    public array $places = [];

    /** @var array<string, bool> whether a place's distance was measured from where the person is (so its card may show it) */
    public array $measured = [];

    /** @var array<string, array{name: string, lat: float, lng: float}> the point each place was searched from (its card's distance and Get there) */
    public array $from = [];

    /** @var array<string, string> what a place was searched for and lists, by id ("Wi-Fi", "vegan food") */
    public array $featured = [];

    /** @var array<string, true> places searched for in an area they named ("in Merkenich"), not near them */
    public array $within = [];

    /** @var array<string, array{member: int, matched: string, part: ?string, has: ?string}> a park answered for a facility in it: the
     *      facility it was found by (its row is measured to it), what that is ("Football pitch"), its part (a large
     *      park's Veedel) and what the park, or that part, has */
    public array $parks = [];

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
        // With their Paperwork permission for this message (paperwork_answer): their record is read,
        // and changed through Paperwork's own writes.
        private bool $paperwork = false,
    ) {}

    /** Whether their Paperwork record may be read and changed in this message (their permission for it). */
    public function paperwork(): bool
    {
        return $this->paperwork;
    }

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
    public static function definitions(bool $personal = false, bool $weather = true, bool $paperwork = false): array
    {
        $kinds = self::kindsWithPlaces();
        $none = ['type' => 'object', 'properties' => (object) []];

        return [
            ['name' => 'where_am_i', 'description' => 'Where the person is: their nearest KVB stop and Veedel, from their browser\'s location or the start they chose, and how that\'s known.', 'input_schema' => $none],
            ['name' => 'departures', 'description' => 'Live departures at a KVB stop, by line and direction. Without a stop: at the stop nearest the person.',
                'input_schema' => ['type' => 'object', 'properties' => ['stop' => ['type' => 'string', 'description' => 'a KVB stop name, optional']]]],
            ['name' => 'plan_trip', 'description' => 'The way to a place by bus, tram and train, from where the person is or from the start they name, with Expadu\'s place finder. When a name fits several places, it says so and you ask which (show the choice). Also answers "how far is it?".',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'to' => ['type' => 'string', 'description' => 'the place as they named it, e.g. Cafe Nova, Hauptbahnhof, Rheinpark'],
                    'area' => ['type' => 'string', 'description' => 'a Veedel they named with it, optional'],
                    'from' => ['type' => 'string', 'description' => 'the start they named ("from Neumarkt"); leave out to start from where they are'],
                    'from_area' => ['type' => 'string', 'description' => 'a Veedel they named with the start, optional'],
                    'by' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => TripAnswers::KINDS], 'description' => 'only these ways of travelling ("by tram"); S-Bahn and regional trains are train'],
                    'avoid' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => TripAnswers::KINDS], 'description' => 'ways of travelling they ruled out ("without trains")'],
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
                    'leave_out_earlier' => ['type' => 'boolean', 'description' => 'only when they turned your suggestions down ("no", "something else", "not these"): leaves those out. Asking again, or narrowing ("I prefer coffee shops", "cheaper", "closer"), is not turning down.'],
                ], 'required' => ['day']]],
            ['name' => 'search_places', 'description' => 'Places in Cologne from Expadu\'s catalogue (cafes, bars, museums, parks, sports places…), with verified hours where known: nearest to the person first, or, with an area, in that area from its middle.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'kinds' => ['type' => 'array', 'items' => ['type' => 'string', ...($kinds !== [] ? ['enum' => $kinds] : [])], 'description' => 'kinds of place, only those Expadu lists; "a place to work" or "laptop" is cafe, library and coworking, but "coworking" alone is coworking; "coffee shop" is cafe; a pool or "swim" is swimming'],
                    'open_now' => ['type' => 'boolean', 'description' => 'only places open now, by their listed hours; only when they ask for open now'],
                    'step_free' => ['type' => 'boolean', 'description' => 'only places whose step-free access is listed'],
                    'features' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'only places that list these, and only when they asked for that fact: wifi, outdoor_seating, wheelchair, diet:vegan|vegetarian|halal|kosher|gluten_free, cuisine:<word> (italian, pizza, turkish, vietnamese, burger…)'],
                    'activity' => ['type' => 'string', 'enum' => PlaceCapabilities::ACTIVITIES],
                    'area' => ['type' => 'string', 'description' => 'a Cologne Veedel, or "city centre" (the old town and Neustadt), optional; keep the area of the conversation when they don\'t name another'],
                    'mood' => ['type' => 'string', 'enum' => Constraints::MOODS],
                    'for_children' => ['type' => 'boolean', 'description' => 'for a child to play ("my kid", "for the kids"): playgrounds, those with the activity\'s gear (football goals) first'],
                    'to_work' => ['type' => 'boolean', 'description' => 'to sit and work with a laptop: never places that say no laptops then, and those listing Wi-Fi, sockets or laptops welcome first'],
                    'open_at' => ['type' => 'string', 'description' => 'HH:MM today: only places open then, by their listed hours'],
                    'indoors' => ['type' => 'boolean', 'description' => 'only places indoors'],
                    'free' => ['type' => 'boolean', 'description' => 'only places that cost nothing'],
                    'leave_out_earlier' => ['type' => 'boolean', 'description' => 'only when they turned your suggestions down ("no", "something else", "not these"): leaves those out. Asking again, or narrowing ("I prefer coffee shops", "cheaper", "closer"), is not turning down.'],
                ]]],
            ...($weather ? [['name' => 'weather', 'description' => 'Cologne\'s weather now and for the next hours.', 'input_schema' => $none]] : []),
            PlanDay::definition(),
            ['name' => 'guidance', 'description' => 'Expadu\'s reviewed guides to German paperwork (registration, residence permits, tax ID, insurance…) matching some words: their titles. Paperwork and legal questions are answered only by these guides, never by you.',
                'input_schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'a few words, e.g. Anmeldung, blue card, tax ID']], 'required' => ['query']]],
            $paperwork
                ? ['name' => 'paperwork', 'description' => 'The person\'s own Paperwork record, shown to them as a card: the answers it keeps (with each key) and their open tasks with each step\'s id. They allowed you to read it for this message.', 'input_schema' => $none]
                : ['name' => 'paperwork', 'description' => 'Shows the person their own Paperwork record as a card: their checklist for the next step and their next date. You don\'t get its contents.', 'input_schema' => $none],
            ['name' => 'my_trip', 'description' => 'Shows the person their trip as a card: the trip under way, else the way to their next appointment today (when to leave, their tram, where to get off). You don\'t get its contents.',
                'input_schema' => ['type' => 'object', 'properties' => ['ask' => ['type' => 'string', 'enum' => ['leave', 'status', 'stop', 'arrive', 'next'], 'description' => 'what they asked: when to leave, whether it runs on time, where to get off, when they arrive, what\'s next']]]],
            ['name' => 'my_next', 'description' => 'Shows the person their next plan or appointment as a card. You don\'t get its contents.',
                'input_schema' => ['type' => 'object', 'properties' => ['appointment' => ['type' => 'boolean', 'description' => 'they asked about appointments only']]]],
            ['name' => 'my_places', 'description' => 'The person\'s saved places (Home, Work and others) by name and Veedel.', 'input_schema' => $none],
            ...self::actions($paperwork),
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
            'where_am_i' => 'location', 'plan_trip' => 'trip', 'plan_day' => 'planner', 'my_trip' => 'your_trip', 'my_next' => 'your_plans',
            // Read only with their Paperwork permission; else a card it never reads.
            'paperwork' => $this->paperwork ? 'paperwork_record' : 'paperwork', default => $name,
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
                'paperwork' => match (true) {
                    $this->paperwork => $this->paperworkRecord(),
                    // The card asking for their permission says what's needed: no pointer to Paperwork above it.
                    $this->askPaperworkPermission() => $this->line('pp', 'Their Paperwork record can be read only with their permission for this message: a card asks them for it.'),
                    default => $this->own('pw', app(OwnCards::class)->paperwork($this->user, $this->now), 'their Paperwork record'),
                },
                'place_feedback', 'set_my_place', 'add_to_day', 'remove_from_day', 'swap_in_day', 'clear_day',
                'change_paperwork_fact', 'paperwork_step', 'paperwork_report' => $this->act($name, $input),
                'open_in_app' => $this->openInApp($input),
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
            $this->timings[] = [$name.' '.json_encode($input, JSON_UNESCAPED_UNICODE), (int) round((hrtime(true) - $started) / 1e6)];
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
        $this->cards['here'] = array_filter(['type' => 'where', 'area' => $area, 'from' => $from, 'basis' => $this->basis(),
            'stop' => $stop === null ? null : ['name' => $stop['name'], 'walk_min' => $stop['walk_min'], 'lines' => $stop['lines']]], fn ($v) => $v !== null);

        return $this->line('here', ucfirst($from).': '.($area !== null ? "in {$area}" : 'outside the Veedel Expadu knows')
            .($stop !== null ? ". Nearest KVB stop: {$stop['name']}, {$stop['walk_min']} min walk (lines ".implode(', ', $stop['lines']).')' : '').'.');
    }

    /** How where they are is known, as a key the page words itself. */
    private function basis(): string
    {
        return match ($this->location->source) {
            LocationSource::Live => is_numeric(request()->input('from_place')) ? 'saved_place' : ($this->location->label === 'Your location' ? 'browser' : 'chosen'),
            LocationSource::Confirmed => 'confirmed',
            LocationSource::Ping => 'last_shared',
            LocationSource::Area => request()->input('from_area') === $this->location->label ? 'area' : 'home_area',
            LocationSource::None => 'unknown',
        };
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
        $this->cards[$id] = ['type' => 'departures', 'board' => array_intersect_key($board, array_flip(['name', 'station', 'time_basis', 'is_stale', 'observed_at', 'areas', 'lines', 'lifts', 'notices']))];

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
        // "Café Nova in Deutz" (a tapped choice, copied as is) is Café Nova, in Deutz, whether or not the area came too.
        $split = function (mixed $name, mixed $area): array {
            $name = trim((string) $name);
            $area = is_string($area) && trim($area) !== '' ? trim($area) : null;
            if (preg_match('/^(.+?)\s+in\s+(.+)$/iu', $name, $m) === 1 && PlanDay::areas([$m[2]]) !== []) {
                return [trim($m[1]), $area ?? PlanDay::areas([$m[2]])[0]];
            }

            return [$name, $area];
        };
        [$to, $area] = $split($input['to'] ?? '', $input['area'] ?? null);
        [$from, $fromArea] = $split($input['from'] ?? '', $input['from_area'] ?? null);
        // "How far is it?" is about the place this conversation's last trip went to, by its name and
        // area. Asked for by name again, the place finder picks the one meant before and offers the others.
        $key = fn (string $name): string => Destinations::key($name);
        $before = ($input['how_far'] ?? false) === true
            ? collect($this->earlier['places'] ?? [])->first(fn (array $p): bool => $key($p['name']) === $key($to) && ($area === null || $p['area'] === $area)) : null;
        $area ??= $before['area'] ?? null;
        // ...from the start that trip had, unless they name another.
        if ($from === '' && isset($before['start']['name'])) {
            [$from, $fromArea] = [$before['start']['name'], $before['start']['area'] ?? null];
        }
        $answer = app(TripAnswers::class)->toPlace($this->user, [
            'place' => $to,
            'area' => $area,
            'from' => $from !== '' ? $from : null,
            'from_area' => $fromArea,
            'by' => is_array($input['by'] ?? null) ? $input['by'] : [],
            'avoid' => is_array($input['avoid'] ?? null) ? $input['avoid'] : [],
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
        $to = isset($extra['destination']['name']) ? 'Trip'.(isset($extra['start']['name']) ? " from \"{$extra['start']['name']}\"".(isset($extra['start']['area']) ? " in {$extra['start']['area']}" : '') : '')
            ." to \"{$extra['destination']['name']}\"".(isset($extra['destination']['area']) ? " in {$extra['destination']['area']}" : '').': ' : '';

        // A major problem on the way, in its own checked words, so the answer agrees with the headline.
        $major = isset($extra['major']) ? "\nMajor problem on the way: {$extra['major']['title']}.".($extra['major']['text'] !== null ? " {$extra['major']['text']}" : '')
            .($extra['major']['todo'] !== null ? " {$extra['major']['todo']}" : '') : '';

        return $this->line($id, $to.trim(($extra['title'] ?? '').' '.$answer['text']).$major);
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
            // What was looked for, so "nothing free is on" is a fact the answer may state, citing it.
            return $this->line('n'.(++$this->count), 'Nothing'.(($input['free'] ?? false) === true ? ' free' : '').' is listed '.($day->isSameDay($this->now) ? 'today' : 'on '.$day->format('l j F'))
                .($topic !== '' ? " for \"{$topic}\"" : '').(isset($input['from']) || isset($input['until']) ? ' at that time' : '').'.');
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
        $asked = $this->clock($input['open_at'] ?? null, $this->now);
        $at = $asked ?? $this->now;
        // "Open now" or "open at 19:30": only places whose listed hours say so (OpeningAnswer).
        $mustBeOpen = $asked !== null || ($input['open_now'] ?? false) === true;
        $named = is_string($input['area'] ?? null) && trim($input['area']) !== '' ? trim($input['area']) : null;
        $areas = $named !== null ? PlanDay::areas([$named]) : [];
        if ($named !== null && $areas === []) {
            return $this->line('n'.(++$this->count), "No Veedel is called \"{$named}\". Places are found by Cologne's Veedel (e.g. Neustadt-Süd for the Südstadt), or the city centre.");
        }
        $wanted = array_values(array_unique(array_filter(is_array($input['kinds'] ?? null) ? $input['kinds'] : [], 'is_string')));
        // Only kinds Expadu has places of: an empty one ("coffee_tea_shop", a shop) would say "nothing" while there are hundreds of cafés.
        $kinds = array_values(array_intersect($wanted, self::kindsWithPlaces()));
        if ($wanted !== [] && $kinds === []) {
            return $this->line('n'.(++$this->count), 'Expadu lists no '.implode(' or ', array_map(fn (string $k): string => self::kindName($k, plural: true), $wanted)).' yet.');
        }
        // "activity", or the "activities" the model sometimes sends instead.
        $activity = is_string($input['activity'] ?? null) ? $input['activity']
            : (is_array($input['activities'] ?? null) && is_string($input['activities'][0] ?? null) ? $input['activities'][0] : null);
        // An activity that's also a kind of place ("swimming": pools and baths) is searched as the kind.
        if ($activity !== null && $kinds === [] && in_array($activity, self::kindsWithPlaces(), true)) {
            [$kinds, $activity] = [[$activity], null];
        }
        // Playgrounds answer no activity (places session, 23 Oct 2026: a child's ask, never an adult's):
        // asked for with one ("my kid, football"), those listing it come first ("with football goals").
        $plays = null;
        if (($input['for_children'] ?? false) === true) {
            $kinds = ['playground'];
        }
        if ($activity !== null && $kinds === ['playground']) {
            [$plays, $activity] = [$activity, null];
        }
        $leaveOut = ($input['leave_out_earlier'] ?? false) === true ? array_keys($this->earlier['spot']) : [];
        // In an area named, from its middle (owner's test, 23 Oct 2026: "places to work in city center"
        // were sorted by the home Veedel at the city's edge); else nearest to the person.
        $middle = $areas !== [] ? DB::table('veedels')->whereIn('name', $areas)->whereNotNull('centroid_lat')
            ->selectRaw('avg(centroid_lat) as lat, avg(centroid_lng) as lng')->first() : null;
        [$lat, $lng] = match (true) {
            $middle?->lat !== null => [(float) $middle->lat, (float) $middle->lng],
            $this->location->hasOrigin() => [(float) $this->location->lat, (float) $this->location->lng],
            default => [CandidateRepository::COLOGNE_LAT, CandidateRepository::COLOGNE_LNG],
        };
        // How far a place is, shown on its card, only from where the person really is or starts.
        $measured = $middle?->lat === null && $this->location->hasOrigin() && $this->location->source !== LocationSource::Area;
        // Asked for one kind or a sport: up to the whole list of it; for several ("a place to work": cafés,
        // libraries, coworking), a share of each; for anything, two of a kind, for variety.
        $perKind = match (true) {
            count($kinds) > 1 => max(2, intdiv(self::SHOWN, count($kinds))),
            $kinds !== [] || $activity !== null => self::SHOWN,
            default => 2,
        };
        $stepFree = $this->user->needs_step_free || ($input['step_free'] ?? false) === true;
        // Facts a place must list ("wifi", "diet:vegan"): few places list them, so the search reads further.
        $features = array_values(array_unique(array_filter(is_array($input['features'] ?? null) ? $input['features'] : [], fn (mixed $f): bool => is_string($f) && PlaceAmenities::valid($f))));
        $work = ($input['to_work'] ?? false) === true;
        // Few places list hours, facts, a playground's goals or what they say of laptops: for those, the search reads further.
        $deep = $mustBeOpen || $features !== [] || $plays !== null || $work;
        $stepFree = $stepFree || in_array('wheelchair', $features, true);
        $nearest = collect($this->catalogue->nearestSpots(new Constraints(
            windowStart: $at,
            windowEnd: $at->addHours(2)->min($at->endOfDay()),
            areas: $areas,
            categories: $kinds,
            activities: array_values(array_filter([$activity])),
            moods: array_values(array_filter([is_string($input['mood'] ?? null) ? $input['mood'] : null])),
            // Their own step-free need, from their settings (never told to the AI), or asked for in words.
            needs: $stepFree ? ['step_free'] : [],
            features: $features,
            // When it must be open then, or list a fact, more of each kind are read: most have no listed hours or facts.
        ), $lat, $lng, $deep ? max($perKind * 4, 12) : $perKind, $deep ? self::SHOWN * 8 : self::SHOWN * 3));
        // A pitch or a playground in a named park is that park, once, with what it has (places
        // session's FacilityGroups, 23 Oct 2026: "Carola-Williams-Park · Football pitch · 2 playgrounds").
        $parents = app(FacilityGroups::class)->parents($nearest->map(fn (Candidate $c): int => (int) substr($c->id, 5))->all());
        // A park too big to walk round is one place per Veedel (places session, 23 Oct 2026): its part.
        $large = app(FacilityGroups::class)->large(array_values(array_unique($parents)));
        $part = fn (Candidate $c): ?string => isset($large[$parents[(int) substr($c->id, 5)] ?? 0]) ? $c->veedel : null;
        $park = fn (Candidate $c): int => $parents[(int) substr($c->id, 5)] ?? (int) substr($c->id, 5);
        $matches = $nearest
            ->filter(fn (Candidate $c): bool => ($areas === [] || in_array($c->veedel, $areas, true))
                && ! ($this->affinities?->isHidden('spot', (int) substr($c->id, 5)) ?? false)
                && ! in_array((int) substr($c->id, 5), $leaveOut, true) && ! in_array($park($c), $leaveOut, true)
                && (($input['indoors'] ?? false) !== true || ! $c->outdoor)
                // To work there: never a place that says no laptops then (places session's website facts).
                && (! $work || self::laptopsWelcome($c, $at) !== false)
                && (($input['free'] ?? false) !== true || $c->costTier === 'free'))
            ->values();
        // Listed as closed then: never offered; asked for open: only those whose listed hours say so.
        $found = $matches->filter(fn (Candidate $c): bool => $mustBeOpen ? OpeningAnswer::isOpenAt($c, $at) === true : OpeningAnswer::isOpenAt($c, $at) !== false)
            // Nearest first, and a place with its own name (a park's) before a generic one at a like distance.
            ->sortBy(fn (Candidate $c): float => ($c->distanceKmFromOrigin ?? INF) + ($park($c) === (int) substr($c->id, 5) && ($c->placeFacts['generic_name'] ?? false) === true ? 0.75 : 0)
                + ($plays !== null && ! in_array($plays, array_map('trim', explode(';', (string) ($c->placeFacts['practical']['sport']['value'] ?? ''))), true) ? 1.5 : 0)
                + ($work && ! self::workFriendly($c) ? 0.5 : 0))
            // One per place: two pitches in one park are the park once, its nearest; a chain's
            // branches in different Veedel stay apart.
            ->unique(fn (Candidate $c): string => $park($c) !== (int) substr($c->id, 5) ? 'park:'.$park($c).'|'.$part($c) : mb_strtolower(self::name($c)).'|'.$c->veedel)
            ->groupBy('category')->flatMap(fn ($kind) => $kind->take($perKind))
            ->sortBy(fn (Candidate $c): float => ($c->distanceKmFromOrigin ?? INF) + ($park($c) === (int) substr($c->id, 5) && ($c->placeFacts['generic_name'] ?? false) === true ? 0.75 : 0)
                + ($plays !== null && ! in_array($plays, array_map('trim', explode(';', (string) ($c->placeFacts['practical']['sport']['value'] ?? ''))), true) ? 1.5 : 0)
                + ($work && ! self::workFriendly($c) ? 0.5 : 0))
            ->take(self::SHOWN)->values();
        $found = $this->asParks($found, $parents, $large, $at, $lat, $lng);
        $where = match (true) {
            $areas === PlanDay::CENTRE => 'in the city centre',
            $areas !== [] => 'in '.implode(', ', $areas),
            default => null,
        };
        // Nothing beyond what they were shown: those again, never an empty answer (owner, 23 Oct 2026:
        // "I couldn't find any other football place" on a repeat ask).
        if ($found->isEmpty() && $leaveOut !== []) {
            return "Nothing fits beyond the places they were shown before, so here are those again:\n"
                .$this->searchPlaces([...$input, 'leave_out_earlier' => false]);
        }
        if ($found->isEmpty()) {
            // Places of that kind, none open then: why ("closed on Sundays", "no hours listed").
            $why = $mustBeOpen ? OpeningAnswer::whyNoneOpen($matches, $at, $kinds !== [] && count($kinds) === 1 ? self::kindName($kinds[0], plural: true) : 'places') : null;

            $thing = $kinds !== [] && count($kinds) === 1 ? self::kindName($kinds[0]) : 'place';
            $lists = $features !== [] ? ' lists '.self::featureWords($features) : ' is listed for that';

            return $this->line('n'.(++$this->count), $why ?? 'No'.(($input['free'] ?? false) === true ? ' free' : '').(($input['indoors'] ?? false) === true ? ' indoor' : '')." {$thing}"
                .($where !== null ? " {$where}" : ($this->location->hasOrigin() ? ' nearby' : '')).$lists.'.');
        }

        // Nearest first ("closer to me" means the first ones), but no distances: where they are stays here.
        return ($features !== [] ? 'Only places that list '.self::featureWords($features).'. ' : '').match (true) {
            $where !== null => ucfirst($where).($named !== null && ! in_array(mb_strtolower($named), array_map('mb_strtolower', $areas), true) ? " (\"{$named}\")" : '').', nearest its middle first:',
            $this->location->hasOrigin() => 'Nearest to the person first:',
            default => "Across Cologne (where they are isn't known):",
        }."\n".$found->map(function (Candidate $c) use ($at, $measured, $lat, $lng, $areas, $stepFree, $features, $work): string {
            $this->places[$c->id] = $c;
            $this->measured[$c->id] = $measured;
            if ($areas !== []) {
                $this->within[$c->id] = true;
            }
            if ($features !== []) {
                $this->featured[$c->id] = self::featureWords($features);
            }
            // Where its card measures from: the area's middle, or where the person is or starts.
            $this->from[$c->id] = ['name' => match (true) {
                $areas === PlanDay::CENTRE => 'City centre',
                $areas !== [] => implode(', ', $areas),
                default => $this->origin(),
            }, 'lat' => $lat, 'lng' => $lng];

            // Step-free said only when they need it or asked (owner's preview, 23 Oct 2026: never unasked).
            return $this->line($c->id, self::placeText($c, $at, $at->equalTo($this->now), $stepFree, $this->parks[$c->id]['has'] ?? null)
                // To work there: what its sources say of laptops, and that nothing is listed when nothing is.
                .($work ? ' '.self::laptopSaid($c) : '')
                .(isset($this->earlier['spot'][(int) substr($c->id, 5)]) ? ' (You suggested it earlier.)' : ''));
        })->implode("\n");
    }

    /**
     * Facilities found in a named park, as the park: its name, facts and what it has, at the
     * facility's point (walk to the pitch, not the park's middle). A park that can't be read stays
     * the facility.
     *
     * @param  Collection<int, Candidate>  $found
     * @param  array<int, int>  $parents  facility id => park id
     * @return Collection<int, Candidate>
     */
    private function asParks(Collection $found, array $parents, array $large, CarbonImmutable $at, float $lat, float $lng): Collection
    {
        $folded = $found->filter(fn (Candidate $c): bool => isset($parents[(int) substr($c->id, 5)]));
        if ($folded->isEmpty()) {
            return $found;
        }
        $ids = array_values(array_unique($folded->map(fn (Candidate $c): int => $parents[(int) substr($c->id, 5)])->all()));
        // The fact subqueries trip Postgres's JIT (3–4 s); see CandidateRepository::spotCandidates.
        $parks = collect(DB::transaction(function () use ($ids, $at, $lat, $lng): array {
            DB::statement('SET LOCAL jit = off');

            return $this->catalogue->byIds(array_map(fn (int $id): string => "spot:{$id}", $ids), $at, $lat, $lng);
        }))->keyBy('id');
        $groups = app(FacilityGroups::class);
        $counts = $groups->counts($ids);

        return $found->map(function (Candidate $c) use ($parents, $parks, $counts, $large, $groups): Candidate {
            $member = (int) substr($c->id, 5);
            $park = isset($parents[$member]) ? $parks->get('spot:'.$parents[$member]) : null;
            if (! $park instanceof Candidate) {
                return $c;
            }
            $matched = PlaceDisplayName::kind($c->category, $c->placeFacts['practical']['sport']['value'] ?? null);
            // A large park's part is its own place: "Äußerer Grüngürtel (Klettenberg)", with what that part has,
            // its id the park's and its part ("spot:12#Klettenberg"), so two parts are two cards and two rows.
            $part = isset($large[$parents[$member]]) ? $c->veedel : null;
            $id = $part === null ? $park->id : "{$park->id}#{$part}";
            $this->parks[$id] = ['member' => $member, 'matched' => $matched, 'part' => $part,
                'has' => FacilityGroups::summary($part !== null ? $groups->partCounts($parents[$member], $part) : ($counts[$parents[$member]] ?? []), [$matched])];

            return new Candidate(...[...get_object_vars($park), 'id' => $id, 'name' => $part === null ? $park->name : "{$park->name} ({$part})",
                'veedel' => $part ?? $park->veedel, 'lat' => $c->lat, 'lng' => $c->lng,
                'distanceKmFromOrigin' => $c->distanceKmFromOrigin, 'travelMinutesFromOrigin' => $c->travelMinutesFromOrigin]);
        })->unique('id')->values();
    }

    /**
     * The kinds of place Expadu has at least one suggestable place of, and the broad
     * kinds holding them; cached for the hour as plain strings.
     *
     * @return list<string>
     */
    public static function kindsWithPlaces(): array
    {
        return Cache::remember('composer:place-kinds', now()->addHour(), function (): array {
            $have = app(DestinationGrouping::class)->eligible(Spot::query(), true)->distinct()->pluck('spots.category')
                ->map(fn ($category): string => $category instanceof SpotCategory ? $category->value : (string) $category)->all();
            $coarse = array_values(array_filter(SpotCategory::placesCoarse(), fn (string $selector): bool => array_intersect(SpotCategory::finesForSelector($selector), $have) !== []));

            return array_values(array_unique([...$have, ...$coarse]));
        });
    }

    /** Who's asking (their saved places, for the cards). */
    public function person(): User
    {
        return $this->user;
    }

    /** Where the person is or starts, by the name the page knows it by. */
    private function origin(): string
    {
        return match ($this->location->source) {
            LocationSource::Live, LocationSource::Confirmed => (string) ($this->location->label ?: 'Your location'),
            LocationSource::Ping => 'Your last location',
            LocationSource::Area => (string) $this->location->label,
            LocationSource::None => 'Cologne',
        };
    }

    /** A place's name as said: an import's "Headquarter[s]" is "Headquarters". */
    public static function name(Candidate $c): string
    {
        return self::clean($c->name);
    }

    /**
     * Facts searched for, as said: "Wi-Fi and vegan food".
     *
     * @param  list<string>  $features  PlaceAmenities keys
     */
    public static function featureWords(array $features): string
    {
        return implode(' and ', array_map(fn (string $f): string => match (true) {
            $f === 'wifi' => 'Wi-Fi', $f === 'outdoor_seating' => 'outdoor seating', $f === 'wheelchair' => 'step-free access',
            str_starts_with($f, 'diet:') => str_replace('_', '-', substr($f, 5)).' food', default => ucfirst(str_replace('_', ' ', substr($f, 8))).' food',
        }, $features));
    }

    /**
     * What a place is, as said: its kind, and a playground the city lists with goals as one
     * ("Playground with football goals": where a child plays football, never an adult pitch).
     */
    public static function kindOf(Candidate $c): string
    {
        $sports = array_map('trim', explode(';', mb_strtolower((string) ($c->placeFacts['practical']['sport']['value'] ?? ''))));

        return match (true) {
            $c->category === 'playground' && in_array('soccer', $sports, true) => 'Playground with football goals',
            default => SpotCategory::tryFrom($c->category)?->label() ?? ucfirst(str_replace('_', ' ', $c->category)),
        };
    }

    /**
     * Whether a place says laptops are welcome at a time, from its listed facts (OSM laptops,
     * laptops:conditional): false for "no", or "limited" on a day its condition names ("Sa-Su");
     * null when it says nothing.
     */
    public static function laptopsWelcome(Candidate $c, CarbonImmutable $at): ?bool
    {
        $practical = $c->placeFacts['practical'] ?? [];
        $laptops = mb_strtolower((string) ($practical['laptops']['value'] ?? ''));
        if ($laptops === 'no') {
            return false;
        }
        if ($laptops === 'limited') {
            return ! self::onDays((string) ($practical['laptops:conditional']['value'] ?? ''), $at);
        }

        return $laptops === 'yes' ? true : null;
    }

    /** What people's answers are about, as said ("good for laptop work"), by the place fact each feeds. */
    private const PEOPLE_SAY = ['laptops' => 'good for laptop work', 'internet_access' => 'has Wi-Fi', 'power_supply' => 'has sockets', 'quiet' => 'quiet enough to work'];

    /**
     * What a place's sources say of working there, each by its source (laptop answers design, 23 Oct
     * 2026): its website ("Its website says laptops aren't allowed at weekends."), its listing ("It
     * lists laptops as welcome."), people who've been there with their anonymous counts only
     * ("People who've been there say it's good for laptop work (9 of 11)."), or that nothing is listed.
     */
    public static function laptopSaid(Candidate $c): string
    {
        $practical = $c->placeFacts['practical'] ?? [];
        $laptops = mb_strtolower((string) ($practical['laptops']['value'] ?? ''));
        $said = [];
        if (($practical['laptops']['provider'] ?? null) !== 'people' && in_array($laptops, ['yes', 'no', 'limited'], true)) {
            $who = ($practical['laptops']['provider'] ?? null) === 'website' || ! str_contains((string) ($practical['laptops']['source_url'] ?? ''), 'openstreetmap.org')
                ? 'Its website says' : 'It lists';
            $days = (string) ($practical['laptops:conditional']['value'] ?? '');
            $when = match (true) {
                preg_match('/\bsa\s*-\s*su\b/i', $days) === 1 => 'at weekends',
                preg_match('/\bmo\s*-\s*fr\b/i', $days) === 1 => 'on weekdays',
                $days !== '' => "on {$days}",
                default => 'at some times',
            };
            $said[] = match ($laptops) {
                'yes' => $who === 'It lists' ? 'It lists laptops as welcome.' : 'Its website says laptops are welcome.',
                'no' => $who === 'It lists' ? 'It lists laptops as not allowed.' : 'Its website says laptops aren\'t allowed.',
                default => "{$who} laptops aren't allowed {$when}.",
            };
        }
        // People's answers: only their counts (PlaceWorkAnswers::totals), never who.
        $people = array_filter(self::PEOPLE_SAY, fn (string $_, string $fact): bool => ($practical[$fact]['provider'] ?? null) === 'people', ARRAY_FILTER_USE_BOTH);
        if ($people !== []) {
            $totals = app(PlaceWorkAnswers::class)->totals((int) substr($c->id, 5));
            foreach ($people as $fact => $what) {
                $total = $totals[array_search($fact, PlaceWorkAnswers::QUESTIONS, true)] ?? null;
                if ($total !== null) {
                    $said[] = match ($total['value']) {
                        'yes' => "People who've been there say it's {$what} ({$total['yes']} of {$total['total']}).",
                        'no' => "People who've been there say it isn't {$what} (".($total['total'] - $total['yes'])." of {$total['total']}).",
                        default => "People who've been there are mixed on whether it's {$what} ({$total['yes']} of {$total['total']} said yes).",
                    };
                }
            }
        }

        return $said === [] ? 'Laptop work isn\'t listed yet.' : implode(' ', $said);
    }

    /** A place to work at: it lists Wi-Fi, sockets or laptops welcome. */
    private static function workFriendly(Candidate $c): bool
    {
        $practical = $c->placeFacts['practical'] ?? [];

        return PlaceAmenities::has($practical, 'wifi') || mb_strtolower((string) ($practical['power_supply']['value'] ?? '')) === 'yes'
            || mb_strtolower((string) ($practical['laptops']['value'] ?? '')) === 'yes';
    }

    /** Whether a condition's days ("Sa-Su", "Mo-Fr", "Sa,Su", "no @ (Sa-Su)") include the day of `$at`. */
    private static function onDays(string $condition, CarbonImmutable $at): bool
    {
        $days = ['mo', 'tu', 'we', 'th', 'fr', 'sa', 'su'];
        $today = $days[$at->setTimezone('Europe/Berlin')->dayOfWeekIso - 1];
        preg_match_all('/\b(mo|tu|we|th|fr|sa|su)\b(?:\s*-\s*\b(mo|tu|we|th|fr|sa|su)\b)?/i', $condition, $ranges, PREG_SET_ORDER);
        foreach ($ranges as $range) {
            $from = array_search(mb_strtolower($range[1]), $days, true);
            $to = array_search(mb_strtolower($range[2] ?? $range[1]), $days, true);
            $index = array_search($today, $days, true);
            if ($from <= $to ? ($index >= $from && $index <= $to) : ($index >= $from || $index <= $to)) {
                return true;
            }
        }

        return false;
    }

    /** A name without an import's bracket artefacts ("Headquarter[s]"). */
    public static function clean(string $name): string
    {
        return PlaceDisplayName::clean($name);
    }

    /** A kind as said: "supermarkets", "Café". */
    private static function kindName(string $kind, bool $plural = false): string
    {
        $label = mb_strtolower(SpotCategory::tryFrom($kind)?->label() ?? str_replace('_', ' ', $kind));

        return match (true) {
            ! $plural || str_ends_with($label, 's') => $label,
            preg_match('/[^aeiou]y$/', $label) === 1 => substr($label, 0, -1).'ies',
            default => $label.'s',
        };
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

    /**
     * The changes Composer can make (ComposerActions), as tools: Paperwork's only with their
     * permission for this message; permanent ones never, only the place that makes them.
     *
     * @return list<array<string, mixed>>
     */
    private static function actions(bool $paperwork): array
    {
        $day = ['type' => 'string', 'description' => 'today, tomorrow or YYYY-MM-DD; today when they don\'t say'];
        $registry = app(FactRegistry::class)->all();

        return [
            ['name' => 'place_feedback', 'description' => 'Saves a place for them, marks it as been, more like this or not for me, or takes that back (clear). Done, or proposed when they ask first: the result says which.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'place' => ['type' => 'string', 'description' => 'its id from your lookups ("spot:12"), else its name as they said it'],
                    'area' => ['type' => 'string', 'description' => 'a Veedel they named with it, optional'],
                    'state' => ['type' => 'string', 'enum' => PlaceFeedbackAction::STATES],
                ], 'required' => ['place', 'state']]],
            ['name' => 'set_my_place', 'description' => 'Sets their Home or Work to an address or a place they name. The address stays private: you only learn it\'s set.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'which' => ['type' => 'string', 'enum' => ['home', 'work']],
                    'place' => ['type' => 'string', 'description' => 'the address or place exactly as they said it'],
                    'area' => ['type' => 'string', 'description' => 'a Veedel they named with a place, optional'],
                ], 'required' => ['which', 'place']]],
            ['name' => 'add_to_day', 'description' => 'Adds a place or listing to their plans for a day, at a time that fits its hours, or the time they say.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'item' => ['type' => 'string', 'description' => 'its id from your lookups ("spot:12", "e34@1767225600"), else a place\'s name'],
                    'area' => ['type' => 'string', 'description' => 'a Veedel they named with the place, optional'],
                    'day' => $day,
                    'start' => ['type' => 'string', 'description' => 'HH:MM, optional'], 'end' => ['type' => 'string', 'description' => 'HH:MM, optional'],
                ], 'required' => ['item']]],
            ['name' => 'remove_from_day', 'description' => 'Takes something off their plans for a day, by its name as they said it.',
                'input_schema' => ['type' => 'object', 'properties' => ['item' => ['type' => 'string'], 'day' => $day], 'required' => ['item']]],
            ['name' => 'swap_in_day', 'description' => 'Swaps something in their plans for a day for a place or listing, at the same time.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'item' => ['type' => 'string', 'description' => 'what\'s in their day now, as they said it'],
                    'with' => ['type' => 'string', 'description' => 'the new one\'s id from your lookups, else its name'],
                    'day' => $day,
                ], 'required' => ['item', 'with']]],
            ['name' => 'clear_day', 'description' => 'Clears their plans for a day.', 'input_schema' => ['type' => 'object', 'properties' => ['day' => $day]]],
            ['name' => 'open_in_app', 'description' => 'For what Composer never does itself because it can\'t be undone or is theirs to decide (deleting their Paperwork record, stopping its processing, withdrawing the AI permission or the one to read their Paperwork, deleting their account): shows the exact place that does it, and why. Never to ask for a permission: a card does that by itself. Say one short line; the card says the rest.',
                'input_schema' => ['type' => 'object', 'properties' => ['what' => ['type' => 'string', 'enum' => array_keys(InAppPlaces::PLACES)]], 'required' => ['what']]],
            // Offered with or without their permission: without it, a card asks them for it.
            ...[
                ['name' => 'change_paperwork_fact', 'description' => 'Changes one answer they told you in their Paperwork record. It\'s always proposed: they apply it with a tap, and the card shows what changes in their plan. Needs their permission for this message: without it, a card asks them. Keys and what each answers: '
                    // A key the record has a row for is described by that row ("visa_expires_at: Your visa runs until"),
                    // so "my visa" is the visa's date, not the residence permit's.
                    .$registry->map(fn ($d, string $key): string => "{$key} ({$d->type}".($d->type === 'enum' ? ': '.implode('|', $d->options) : '').'): '
                        .(PaperworkActions::rowLabel($key) ?? $d->question))->implode('; '),
                    'input_schema' => ['type' => 'object', 'properties' => [
                        'key' => ['type' => 'string', 'enum' => $registry->keys()->all()],
                        'value' => ['type' => 'string', 'description' => 'a date as YYYY-MM-DD, yes or no, a whole number, or one of its options'],
                    ], 'required' => ['key', 'value']]],
                ['name' => 'paperwork_step', 'description' => 'Marks a step of one of their Paperwork tasks done, or not done, by the task and step from the paperwork tool.',
                    'input_schema' => ['type' => 'object', 'properties' => [
                        'task' => ['type' => 'string', 'description' => 'the task\'s key, else its title'],
                        'step' => ['type' => 'string', 'description' => 'the step\'s id, else its title'],
                        'done' => ['type' => 'boolean'],
                    ], 'required' => ['task', 'step']]],
                ['name' => 'paperwork_report', 'description' => 'Records on one of their Paperwork tasks that they submitted it (the day), or an appointment they have (the day and time).',
                    'input_schema' => ['type' => 'object', 'properties' => [
                        'task' => ['type' => 'string', 'description' => 'the task\'s key, else its title'],
                        'event' => ['type' => 'string', 'enum' => ['submitted', 'appointment']],
                        'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                        'time' => ['type' => 'string', 'description' => 'HH:MM, for an appointment'],
                        'minutes' => ['type' => 'integer', 'description' => 'how long the appointment is, when they say'],
                        'channel' => ['type' => 'string', 'enum' => ['online', 'post', 'email', 'in_person'], 'description' => 'how they submitted it, when they say'],
                    ], 'required' => ['task', 'event', 'date']]],
            ],
        ];
    }

    /**
     * A change they asked for, made or proposed (ComposerActions), shown as its card; Paperwork's
     * only with their permission for this message.
     *
     * @param  array<string, mixed>  $input
     */
    private function act(string $action, array $input): string
    {
        if (ComposerActions::ACTIONS[$action][1] === 'paperwork' && ! $this->paperwork) {
            return $this->line('n'.(++$this->count), 'Not done: Composer may read and change their Paperwork only with their permission for this message.'
                .($this->askPaperworkPermission() ? ' A card asks them for it.' : ''));
        }
        // A place this conversation showed, said by its name ("save Café Nova"): that one.
        foreach (['place', 'item', 'with'] as $field) {
            if (is_string($input[$field] ?? null) && preg_match('/^(spot:\d+|e\d+@\d+)$/', $input[$field]) !== 1) {
                $shown = array_keys(array_filter($this->earlier['spot'], fn (string $name): bool => Destinations::key($name) === Destinations::key((string) $input[$field])));
                if (count($shown) === 1) {
                    $input[$field] = "spot:{$shown[0]}";
                }
            }
        }
        $made = app(ComposerActions::class)->request($this->user, $action, $input, $this->now);
        if ($made['blocks'] === []) {
            return $this->line('n'.(++$this->count), $made['said']);
        }
        $id = 'x'.(++$this->count);
        // Its cards are Composer's own words, from the records it changed: always shown.
        foreach ($made['blocks'] as $i => $block) {
            $this->cards[$id.($i === 0 ? '' : 'p')] = $block;
        }

        return $this->line($id, $made['said']);
    }

    /**
     * Their permission for Composer to read and change their Paperwork record, asked for on a
     * card (the page sends it with the message again): only when Paperwork's AI is on and they
     * have a record in use.
     */
    private function askPaperworkPermission(): bool
    {
        if (! ProcessingPurpose::ComposerPaperwork->available() || ! BureaucracyPerson::query()->where('account_user_id', $this->user->id)
            ->where('record_status', 'active')->whereNull('processing_stopped_at')->exists()) {
            return false;
        }
        $this->cards['pp'] = ['type' => 'permission', 'purpose' => ProcessingPurpose::ComposerPaperwork->value,
            'title' => 'Let Composer read and change your Paperwork record for this message?',
            // Composer's own words, said when the AI said nothing.
            'text' => 'To read or change your Paperwork record, Composer needs your permission for this message.',
            'sub' => 'Changes to your answers always ask you to apply them; nothing is sent to an authority.',
            // Its own notice, in words (disclosure.notice), from what Composer's request carries.
            'disclosure' => ProcessingPurpose::ComposerPaperwork->disclosure()];

        return true;
    }

    /** What Composer never does itself, and the exact place that does it (InAppPlaces). */
    private function openInApp(array $input): string
    {
        $what = is_string($input['what'] ?? null) ? $input['what'] : '';
        if (InAppPlaces::card($what) === null) {
            // Asked for a place it doesn't have: the ones it has, so the next round picks one.
            return 'No such place. The places are: '.implode(', ', array_keys(InAppPlaces::PLACES)).'. Call open_in_app again with one of them.';
        }
        // "Stop using my Paperwork data" means one of two things: both are offered, the one meant first.
        $both = InAppPlaces::STOPPING[$what] ?? null;
        $id = 'o'.(++$this->count);
        // Each place once, however often it's asked for.
        $shown = array_column(array_filter($this->cards, fn (array $card): bool => ($card['type'] ?? null) === 'handoff'), 'what');
        foreach (array_diff($both === null ? [$what] : [$what, $both], $shown) as $i => $place) {
            $this->cards[$id.($i === 0 ? '' : 'b')] = InAppPlaces::card($place);
        }

        return $this->line($id, $both === null ? 'Shown to them as a card with where to do it and why Composer doesn\'t. Say one short line at most.'
            : 'Shown to them as two cards: stopping Composer reading their Paperwork, and stopping Paperwork processing their record. Say in one short line that they can choose either.');
    }

    /** Their Paperwork record, read with their permission for this message, and shown as its card. */
    private function paperworkRecord(): string
    {
        try {
            $record = app(PaperworkActions::class)->record($this->user, $this->now);
        } catch (ActionRefused $refused) {
            return $this->line('n'.(++$this->count), $refused->getMessage());
        }
        $this->cards['pw'] = [...$record['block'], 'private' => true];

        return $this->line('pw', $record['text']);
    }

    /** Their saved places, by name and Veedel, never where exactly. */
    private function myPlaces(): string
    {
        $places = $this->user->places()->located()->get(['id', 'name', 'category', 'lat', 'lng']);
        if ($places->isEmpty()) {
            return 'They have no saved places.';
        }
        $locations = app(UserLocationService::class);

        // Home and Work as such: their names are often the address typed for them, and addresses stay here.
        return $places->values()->map(fn ($place, int $i): string => $this->line('m'.($i + 1),
            (in_array($place->category, ['home', 'work'], true) ? ucfirst((string) $place->category) : "\"{$place->name}\"")
            .' in '.($locations->veedelAt((float) $place->lat, (float) $place->lng) ?? 'an unknown Veedel')))->implode("\n");
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
    public static function placeText(Candidate $c, ?CarbonImmutable $at = null, bool $now = true, bool $stepFree = false, ?string $has = null): string
    {
        $kind = self::kindOf($c);
        // Open only from its listed hours; assumed hours are never "open" (OpeningAnswer).
        $open = $at === null ? null : OpeningAnswer::isOpenAt($c, $at);
        $hours = match (true) {
            $open === true => ' Open '.($now ? 'now' : 'at '.$at->setTimezone('Europe/Berlin')->format('H:i')).($c->closesAt !== null ? ', until '.$c->closesAt->setTimezone('Europe/Berlin')->format('H:i').' today' : '').'.',
            $open === null => ' Opening hours not listed.',
            default => '',
        };

        // What its listing or website lists; Wi-Fi from people's answers is said as theirs (laptopSaid).
        $lists = array_values(array_diff(PlaceAmenities::listed($c->placeFacts['practical'] ?? []),
            ($c->placeFacts['practical']['internet_access']['provider'] ?? null) === 'people' ? ['Wi-Fi'] : []));

        return '"'.self::name($c).'" ('.$kind.($c->outdoor ? ', outdoors' : ', indoors').')'.($c->veedel !== null ? " in {$c->veedel}" : '').'.'
            .($has !== null ? ' Has: '.$has.'.' : '')
            .($lists !== [] ? ' Lists: '.implode(', ', $lists).'.' : '')
            .$hours
            .($c->costTier === 'free' ? ' Free.' : '')
            .($stepFree && $c->stepFree() ? ' Step-free access listed.' : '')
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
