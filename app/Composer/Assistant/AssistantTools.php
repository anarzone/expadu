<?php

namespace App\Composer\Assistant;

use App\Bureaucracy\Facts\FactRegistry;
use App\Bureaucracy\RuleSourcePolicy;
use App\Composer\Actions\ActionRefused;
use App\Composer\Actions\ComposerActions;
use App\Composer\Actions\DayActions;
use App\Composer\Actions\InAppPlaces;
use App\Composer\Actions\PaperworkActions;
use App\Composer\Actions\PlaceFeedbackAction;
use App\Composer\Actions\SettingsActions;
use App\Composer\Answers\Destinations;
use App\Composer\Answers\EventAnswers;
use App\Composer\Answers\TripAnswers;
use App\Composer\Candidate;
use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Composer\PlanFit;
use App\Composer\WorkspaceStore;
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
use App\Services\LocationContext;
use App\Services\NearbyStopService;
use App\Services\OpeningHoursParser;
use App\Services\UserLocationService;
use App\Services\WeatherService;
use App\Transit\Dto\GeoPoint;
use App\Transit\FavouriteStops;
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
    public const PERSONAL_IDS = '/^((t|a|h|w|d|m|r|c|b|x|o|plan)\d+|here|pw|trip|next|plan)$/';

    /**
     * What each tool's result sends, as Composer's notice names it (ProcessingPurpose::WORDS).
     * A tool without one can't be sent: ComposerPaperworkNoticeTest fails on it.
     */
    public const CATEGORIES = [
        'where_am_i' => 'your_nearest_stop_and_veedel', 'departures' => 'departures', 'plan_trip' => 'trips_to_places_you_name',
        'notices' => 'transit_notices', 'search_events' => 'events_listed', 'search_places' => 'places_listed', 'find_place' => 'places_listed', 'weather' => 'weather',
        'plan_day' => 'plans_composed', 'guidance' => 'paperwork_guide_titles', 'my_places' => 'your_saved_places_names',
        'paperwork' => 'notes_that_your_own_cards_are_shown', 'my_trip' => 'notes_that_your_own_cards_are_shown',
        'my_next' => 'notes_that_your_own_cards_are_shown', 'open_in_app' => 'notes_that_your_own_cards_are_shown',
        'my_taste' => 'your_taste_if_personalised', 'my_day' => 'your_plans_if_personalised',
        'place_feedback' => 'changes_you_ask_for', 'set_my_place' => 'changes_you_ask_for', 'add_to_day' => 'changes_you_ask_for',
        'remove_from_day' => 'changes_you_ask_for', 'swap_in_day' => 'changes_you_ask_for', 'clear_day' => 'changes_you_ask_for',
        'move_in_day' => 'changes_you_ask_for', 'suggest_for_day' => 'places_listed',
        'save_event' => 'changes_you_ask_for', 'remind_me' => 'changes_you_ask_for',
        'save_stop' => 'changes_you_ask_for', 'set_travel_prefs' => 'changes_you_ask_for', 'plan_trip_for_later' => 'changes_you_ask_for',
        'mute_alerts' => 'changes_you_ask_for', 'dismiss_alert' => 'changes_you_ask_for',
        'set_notifications' => 'changes_you_ask_for', 'set_needs' => 'changes_you_ask_for', 'update_profile' => 'changes_you_ask_for',
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

    /** What "an activity" is when they don't name a kind: a day out's sights, culture and green. */
    private const DAY_OUT = ['museum', 'gallery', 'attraction', 'zoo', 'park', 'viewpoint', 'nature', 'market', 'theatre', 'cinema', 'arts_centre'];

    /** Kinds that may be indoors or out (a sight, an open-air pool, a crag, a kiosk): said only as mapped. */
    private const SETTING_UNSAID = ['attraction', 'swimming', 'climbing', 'kiosk', 'sports_centre'];

    /** @var list<array{0: string, 1: int}> each lookup and its milliseconds, in order (composer:eval reads where the time goes) */
    public array $timings = [];

    /** @var array{now: string, before: list<string>} their message, and their earlier ones in this conversation */
    public array $asked = ['now' => '', 'before' => []];

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
            ['name' => 'plan_trip', 'description' => 'The way to a place by bus, tram and train, from where the person is or from the start they name, with Expadu\'s place finder. When a name fits several places, it says so and you ask which (show the choice). Also answers "how far is it?". To keep the way to a plan on their day, with a reminder when to leave ("plan the way there and remind me"), use plan_trip_for_later instead.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'to' => ['type' => 'string', 'description' => 'the place as they named it, e.g. Cafe Nova, Hauptbahnhof, Rheinpark'],
                    'area' => ['type' => 'string', 'description' => 'a Veedel they named with it, or a square, stop or place they said it is near ("near Neumarkt"), optional'],
                    'from' => ['type' => 'string', 'description' => 'the start they named ("from Neumarkt"); never their home Veedel unless they said it. Leave out to start from where they are'],
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
                    'leave_out_earlier' => ['type' => 'boolean', 'description' => 'only when they turned your suggestions down ("no", "something else", "not these"): leaves those out, with asking_for_others. Asking again, or narrowing ("I prefer coffee shops", "cheaper", "closer"), is not turning down.'],
                    'asking_for_others' => ['type' => 'string', 'description' => 'with leave_out_earlier: their words in this message that turn down what you showed ("something else", "not these"), as they wrote them; without them, nothing is left out'],
                ], 'required' => ['day']]],
            ['name' => 'find_place', 'description' => 'One place they name ("Café Wohnraum", "Rheinpark"), from Expadu\'s catalogue, with what\'s listed of it: its hours, what it has, what its website and people say of laptop work, and who may use it. Whenever they ask about a place they name, look it up with this, not search_places; to save, mark or plan one, use that action with its name straight away instead. When several places have that name, it says so and you ask which (show the choice); when none has, it says so.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'name' => ['type' => 'string', 'description' => 'the place as they named it'],
                    'area' => ['type' => 'string', 'description' => 'its Veedel, or a square, stop or place they said it is near ("near Neumarkt"), when they said one'],
                ], 'required' => ['name']]],
            ['name' => 'search_places', 'description' => 'Places in Cologne from Expadu\'s catalogue (cafes, bars, museums, parks, sports places…), with verified hours where known: nearest to the person first, or, with an area, in that area from its middle.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'kinds' => ['type' => 'array', 'items' => ['type' => 'string', ...($kinds !== [] ? ['enum' => $kinds] : [])], 'description' => 'kinds of place, only those Expadu lists, shops by their kind (clothes, electronics, furniture…); "a place to work" or "laptop" is cafe, library and coworking, but a café or coffee shop to work in is cafe and "coworking" alone is coworking; "coffee shop" is cafe; a pool or "swim" is swimming'],
                    'open_now' => ['type' => 'boolean', 'description' => 'places open now by their listed hours, then open-air ones with no set hours; only when they ask for open now'],
                    'step_free' => ['type' => 'boolean', 'description' => 'only places whose step-free access is listed'],
                    'features' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'only places that list these, and only when they asked for that fact: wifi, outdoor_seating, wheelchair, diet:vegan|vegetarian|halal|kosher|gluten_free, cuisine:<word> (italian, pizza, turkish, vietnamese, burger…)'],
                    'activity' => ['type' => 'string', 'enum' => PlaceCapabilities::ACTIVITIES],
                    'area' => ['type' => 'string', 'description' => 'a Cologne Veedel, or "city centre" (the old town and Neustadt), only one they name or this conversation is about; never their home Veedel unless they said it: without an area, places come nearest them, at home or wherever they are'],
                    'near' => ['type' => 'string', 'description' => 'a square, KVB stop or place they named the places near ("near Neumarkt", "by the Dom"): places around it, nearest first'],
                    'whole_city' => ['type' => 'boolean', 'description' => 'all of Cologne, when they widen where to look ("generally in Cologne", "anywhere", "in the whole city"), not only near them or in an area; with to_work, those listing most for working first'],
                    'mood' => ['type' => 'string', 'enum' => Constraints::MOODS],
                    'for_children' => ['type' => 'boolean', 'description' => 'for a child to play ("my kid", "for the kids"): playgrounds, those with the activity\'s gear (football goals) first'],
                    'to_work' => ['type' => 'boolean', 'description' => 'to sit and work with a laptop: never places that say no laptops then, and those listing Wi-Fi, sockets or laptops welcome first'],
                    'open_at' => ['type' => 'string', 'description' => 'HH:MM today: only places open then, by their listed hours'],
                    'indoors' => ['type' => 'boolean', 'description' => 'only places indoors'],
                    'free' => ['type' => 'boolean', 'description' => 'they want it free: a place with a listed fee, or restricted, is left out; nearest first. Answer in the results\' words: "free" only for "Free to use (listed)", else "public, no fee listed".'],
                    'leave_out_earlier' => ['type' => 'boolean', 'description' => 'only when they turned your suggestions down ("no", "something else", "not these"): leaves those out, with asking_for_others. Asking again, or narrowing ("I prefer coffee shops", "cheaper", "closer"), is not turning down.'],
                    'asking_for_others' => ['type' => 'string', 'description' => 'with leave_out_earlier: their words in this message that turn down what you showed ("something else", "not these"), as they wrote them; without them, nothing is left out'],
                ]]],
            ...($weather ? [['name' => 'weather', 'description' => 'Cologne\'s weather now and for the next hours.', 'input_schema' => $none]] : []),
            PlanDay::definition(),
            ['name' => 'suggest_for_day', 'description' => 'Things they could add to their plan for a day, without changing it: places of the kinds or area they asked for, each shown as a card with an "Add to <day>" button and where it would fit ("after Kolumba, 15:15–16:15"). Use it when they ask to find, suggest or look for more ("find us two activities", "what else is there?", "anything indoors?") while they have a plan that day.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'day' => ['type' => 'string', 'description' => 'today, tomorrow, a weekday (Sunday) or YYYY-MM-DD'],
                    'count' => ['type' => 'integer', 'description' => 'how many they asked for ("two activities": 2); 3 when they don\'t say'],
                    'kinds' => ['type' => 'array', 'items' => ['type' => 'string', ...($kinds !== [] ? ['enum' => $kinds] : [])], 'description' => 'kinds of place they asked for; none for "activities" or "something to do"'],
                    'area' => ['type' => 'string', 'description' => 'a Veedel or "city centre" they named'],
                    'near' => ['type' => 'string', 'description' => 'a square, stop or place they named'],
                    'indoors' => ['type' => 'boolean', 'description' => 'only indoor places'],
                    'free' => ['type' => 'boolean', 'description' => 'only places with no fee listed'],
                ], 'required' => ['day']]],
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
        // Left out only when they asked for others (owner, 23 Oct 2026: asking again isn't turning down).
        if (in_array($name, ['search_places', 'search_events'], true) && ($input['leave_out_earlier'] ?? false) === true && ! $this->askedForOthers($input)) {
            unset($input['leave_out_earlier']);
        }
        $this->looked[] = match ($name) {
            'search_events' => 'listings', 'search_places' => 'places', 'find_place' => 'places', 'suggest_for_day' => 'places', 'my_day' => 'plans', 'my_taste' => 'taste',
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
                'find_place' => $this->findPlace($input),
                'weather' => $this->weatherNow(),
                'plan_day' => $this->planDay($input),
                'suggest_for_day' => $this->suggestForDay($input),
                'swap_in_day' => $this->swapInDay($input),
                'guidance' => $this->guidance($input),
                'paperwork' => match (true) {
                    $this->paperwork => $this->paperworkRecord(),
                    // The card asking for their permission says what's needed: no pointer to Paperwork above it.
                    $this->askPaperworkPermission() => $this->line('pp', 'Their Paperwork record can be read only with their permission for this message: a card asks them for it.'),
                    default => $this->own('pw', app(OwnCards::class)->paperwork($this->user, $this->now), 'their Paperwork record'),
                },
                'open_in_app' => $this->openInApp($input),
                'my_trip' => $this->own('trip', app(OwnCards::class)->trip($this->user, in_array($input['ask'] ?? null, ['leave', 'status', 'stop', 'arrive', 'next'], true) ? $input['ask'] : 'leave', $this->now), 'their trip'),
                'my_next' => $this->own('next', app(OwnCards::class)->next($this->user, $this->plans, ($input['appointment'] ?? false) === true, $this->now), 'their next plan or appointment'),
                'my_places' => $this->myPlaces(),
                'my_taste' => $this->myTaste(),
                'my_day' => $this->myDay(),
                // Every change Composer can make goes the one way, its card included.
                default => isset(ComposerActions::ACTIONS[$name]) ? $this->act($name, $input) : 'Unknown tool.',
            };
        } catch (Throwable) {
            return 'Nothing found: the lookup failed.';
        } finally {
            $this->timings[] = [$name.' '.json_encode($input, JSON_UNESCAPED_UNICODE), (int) round((hrtime(true) - $started) / 1e6)];
        }
    }

    /**
     * Whether the words the AI says ask for others ("something else", "not these") are in their message,
     * with one they hadn't said before: the same question again says nothing new, so nothing is left out.
     */
    private function askedForOthers(array $input): bool
    {
        return $this->theySaid((string) ($input['asking_for_others'] ?? ''));
    }

    /**
     * Whether words the AI says are theirs are in this message, with one they hadn't said before
     * in this conversation: the same question again says nothing new.
     */
    private function theySaid(string $quoted): bool
    {
        $words = fn (string $text): array => array_values(array_filter(explode(' ', self::words($text))));
        $quote = $words($quoted);
        $before = array_merge(...array_map($words, $this->asked['before']), ...[[]]);

        return $quote !== [] && str_contains(' '.self::words($this->asked['now']).' ', ' '.implode(' ', $quote).' ')
            && array_diff($quote, $before) !== [];
    }

    /** Their message is one they sent before in this conversation, word for word. */
    public function askedAgain(): bool
    {
        return in_array(self::words($this->asked['now']), array_map(self::words(...), $this->asked['before']), true);
    }

    /** A distance as said: "300 m", "1.4 km". */
    private static function distance(float $km): string
    {
        return $km < 1 ? max(50, (int) (round($km * 1000 / 50) * 50)).' m' : number_format($km, 1).' km';
    }

    /** Words as compared: lower case, letters and digits, single spaces. */
    public static function words(string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)));
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
        $stops = app(FavouriteStops::class);
        $found = $named !== '' ? $stops->stations($named) : [];
        if (count($found) > 1) {
            return "Several KVB stops fit \"{$named}\": ".implode(', ', $found).'. Ask which one.';
        }
        $name = $named !== '' ? $found[0] ?? null : $this->nearestStop()['name'] ?? null;
        if ($name === null) {
            return $named === '' ? 'Where they are isn\'t known, so there\'s no nearest stop. Ask which stop.'
                : ($stops->readable() ? "No KVB stop is called \"{$named}\"." : FavouriteStops::UNREADABLE);
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
            // "TK Maxx near Neumarkt": near a square, stop or place, so in its Veedel.
            if ($area === null && preg_match('/^(.+?)\s+(?:near|by|at|around)\s+(.+)$/iu', $name, $m) === 1) {
                [$name, $area] = [trim($m[1]), trim($m[2])];
            }
            // An area that isn't a Veedel (a square, a stop): the Veedel it's in (owner's chat, 24 Oct 2026).
            if ($area !== null && PlanDay::areas([$area]) === []) {
                $area = $this->near($area)['veedel'] ?? $area;
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
            // What was looked for, so "nothing free is on" is a fact the answer may state, citing it: all of
            // the city's listings that day, so past those shown before, "nothing else" is too.
            return $this->line('n'.(++$this->count), 'Nothing'.($leaveOut !== [] ? ' else' : '').(($input['free'] ?? false) === true ? ' free' : '').' is listed '.($day->isSameDay($this->now) ? 'today' : 'on '.$day->format('l j F'))
                .($topic !== '' ? " for \"{$topic}\"" : '').(isset($input['from']) || isset($input['until']) ? ' at that time' : '').'.');
        }

        return $shown->map(function (array $poster) use ($occurrences): string {
            $id = 'e'.$poster['id'].'@'.CarbonImmutable::parse($poster['occurrence_start'])->getTimestamp();
            $this->events[$id] = $occurrences->first(fn (array $o): bool => $o['event']->id === $poster['id']
                && $o['starts_at']->getTimestamp() === CarbonImmutable::parse($poster['occurrence_start'])->getTimestamp());

            return $this->line($id, self::eventText($poster).(isset($this->earlier['event'][$poster['id']]) ? ' (You suggested it earlier.)' : ''));
        })->implode("\n");
    }

    /**
     * What they could add to a day's plan, the plan unchanged (owner, 24 Oct 2026): places as the
     * place search finds them, the number asked for, each where it would fit (PlanFit) with its
     * "Add to <day>" proposal; nothing is added until they tap it.
     */
    private function suggestForDay(array $input): string
    {
        $day = PlanDay::dayOf($input['day'] ?? null, $this->now);
        if ($day === null || $day->lessThan($this->now->startOfDay())) {
            return 'Say which day: today or one ahead.';
        }
        $date = $day->toDateString();
        $plan = app(WorkspaceStore::class)->plan($this->user, $date);
        $count = is_numeric($input['count'] ?? null) ? max(1, min(5, (int) $input['count'])) : 3;
        $shown = in_array($date, $this->earlier['plans'] ?? [], true);
        $before = array_keys($this->places);
        // "Activities" are a day out's (owner, 24 Oct 2026): sights, museums, galleries, parks and nature,
        // markets; food and drink when they ask for it.
        $kinds = is_array($input['kinds'] ?? null) && $input['kinds'] !== [] ? $input['kinds'] : array_values(array_intersect(self::DAY_OUT, self::kindsWithPlaces()));
        $searched = $this->searchPlaces([...array_intersect_key($input, array_flip(['area', 'near', 'indoors', 'free'])), 'kinds' => $kinds]);
        $found = array_values(array_diff(array_keys($this->places), $before));
        $inPlan = array_column($plan['slots'] ?? [], 'id');
        // Each as it is on that day, its hours that day's (the search reads today's).
        $onDay = $found === [] ? collect() : collect($this->catalogue->byIds(array_values(array_unique(array_map(fn (string $id): string => explode('#', $id)[0], $found))), $day))->keyBy('id');
        $options = [];
        foreach ($found as $id) {
            $place = $onDay[explode('#', $id)[0]] ?? null;
            $fit = $place !== null && count($options) < $count && ! in_array($id, $inPlan, true) ? app(PlanFit::class)->for($place, $plan, $day) : null;
            if ($fit !== null) {
                $options[$id] = $fit;
            }
        }
        // Only the options are shown and may be named: the rest of the search stays out.
        foreach ($found as $id) {
            if (! isset($options[$id])) {
                unset($this->places[$id], $this->read[$id]);
            }
        }
        if ($options === []) {
            return $this->line('n'.(++$this->count), $found === [] ? $searched : 'Nothing found fits the free time in their plan for '.$day->isoFormat('dddd').'.');
        }
        $where = $day->isoFormat('dddd');
        $lines = [];
        foreach ($options as $id => $fit) {
            // Its "Add to <day>" button: a proposal at the time it fits, made only when they tap it.
            $made = app(ComposerActions::class)->request($this->user, 'add_to_day', ['item' => $id, 'day' => $date, 'start' => $fit['start'], 'end' => $fit['end'],
                '_after' => $shown ? $fit['after'] : null], $this->now, propose: true);
            $card = 'x'.(++$this->count);
            foreach ($made['blocks'] as $i => $block) {
                $this->cards[$card.($i === 0 ? '' : 'p')] = $block;
            }
            // Where it fits: after which stop only when that plan is in this conversation.
            $lines[] = $this->line($id, self::placeText($this->places[$id], $day->setTimeFromTimeString($fit['start']), false, (bool) $this->user->needs_step_free)
                .' Would fit '.($shown && $fit['after'] !== null ? "after \"{$fit['after']}\", " : '')."{$fit['start']}–{$fit['end']}; shown with an Add to {$where} button.");
        }

        return 'Suggestions for their plan for '.$where.' (not added; the plan is unchanged until they tap Add to '.$where.'), '.count($options).' of them:'."\n".implode("\n", $lines);
    }

    /**
     * Swaps a stop in their plan for another, with Undo. For a place they name, that one. For something
     * of a kind ("replace Rheingarten with something indoors", "with a museum"), the replacement is found
     * here, never picked by the AI from a list it might only show (coordinator, 26 Oct 2026: 1 in 4 runs
     * offered one instead of swapping): of that kind (the stop's own when none is said), indoors or free
     * when asked, open for all of the stop's time, listed hours before none listed, then the shortest
     * way from the stop before and on to the next.
     */
    private function swapInDay(array $input): string
    {
        if (is_string($input['with'] ?? null) && trim($input['with']) !== '') {
            return $this->act('swap_in_day', $input);
        }
        $day = PlanDay::dayOf($input['day'] ?? null, $this->now) ?? $this->now->startOfDay();
        $date = $day->toDateString();
        $plan = app(WorkspaceStore::class)->plan($this->user, $date);
        try {
            $slot = DayActions::slot($plan, (string) ($input['item'] ?? ''), DayActions::where($date, $this->now));
        } catch (ActionRefused $refused) {
            return $this->line('n'.(++$this->count), $refused->getMessage());
        }
        $asked = array_values(array_filter(is_array($input['kinds'] ?? null) ? $input['kinds'] : [], 'is_string'));
        $kinds = $asked !== [] ? array_values(array_intersect($asked, self::kindsWithPlaces()))
            : array_values(array_filter([SpotCategory::tryFrom((string) ($slot['category'] ?? ''))?->value]));
        if ($asked !== [] && $kinds === []) {
            return $this->line('n'.(++$this->count), 'Expadu lists no '.implode(' or ', array_map(fn (string $k): string => self::kindName($k, plural: true), $asked)).' yet.');
        }
        $start = CarbonImmutable::parse($slot['start_at'])->setTimezone('Europe/Berlin');
        $end = CarbonImmutable::parse($slot['end_at'])->setTimezone('Europe/Berlin');
        [$lat, $lng] = is_numeric($slot['lat'] ?? null) && is_numeric($slot['lng'] ?? null) ? [(float) $slot['lat'], (float) $slot['lng']]
            : [CandidateRepository::COLOGNE_LAT, CandidateRepository::COLOGNE_LNG];
        $stepFree = (bool) $this->user->needs_step_free;
        $inPlan = array_column($plan['slots'] ?? [], 'id');
        $listed = fn (Candidate $c): bool => ($c->placeFacts['hours']['status'] ?? null) === 'known' && ! $c->hoursAssumed;
        $fits = collect($this->catalogue->nearestSpots(new Constraints(windowStart: $start, windowEnd: $end, categories: $kinds, needs: $stepFree ? ['step_free'] : []),
            $lat, $lng, self::SHOWN * 2, self::SHOWN * 4))
            ->reject(fn (Candidate $c): bool => in_array($c->id, $inPlan, true) || ($this->affinities?->isHidden('spot', (int) substr($c->id, 5)) ?? false))
            ->filter(fn (Candidate $c): bool => (($input['indoors'] ?? false) !== true || self::setting($c) === 'indoors') && (($input['free'] ?? false) !== true || self::freeToUse($c)))
            ->map(fn (Candidate $c): array => [$c, app(PlanFit::class)->instead($c, $plan, $slot)])
            ->filter(fn (array $fit): bool => $fit[1] !== null)
            ->sort(fn (array $a, array $b): int => [! $listed($a[0]), $a[1], $a[0]->distanceKmFromOrigin] <=> [! $listed($b[0]), $b[1], $b[0]->distanceKmFromOrigin]);
        $thing = (($input['indoors'] ?? false) === true ? 'indoor ' : '').(($input['free'] ?? false) === true ? 'free ' : '')
            .(count($kinds) === 1 ? self::kindName($kinds[0]) : 'place');
        if ($fits->isEmpty()) {
            return $this->line('n'.(++$this->count), "No {$thing} near \"{$slot['name']}\" is open for all of {$slot['start_time']}–{$slot['end_time']} that day, so their plan is unchanged.");
        }
        $chosen = $fits->first()[0];
        $this->places[$chosen->id] = $chosen;
        // Its distance is from the stop it replaces, not from them: never shown as theirs.
        $this->measured[$chosen->id] = false;

        return $this->line($chosen->id, 'Found for the swap, the '.$thing.' that fits that time best: '.self::placeText($chosen, $start, false, $stepFree))
            ."\n".$this->act('swap_in_day', ['item' => $slot['id'], 'with' => $chosen->id, 'day' => $date]);
    }

    /**
     * Their plan for a day as it is now, for this conversation's own plan: its stops by time, name,
     * kind and Veedel; their own plans and appointments in it only as theirs.
     */
    public function planText(string $date): ?string
    {
        $plan = app(WorkspaceStore::class)->plan($this->user, $date);
        if (($plan['slots'] ?? []) === []) {
            return null;
        }

        return 'Their plan for '.CarbonImmutable::parse($date, 'Europe/Berlin')->isoFormat('dddd D MMMM').', made in this conversation, as it is now:'."\n"
            .collect($plan['slots'])->sortBy('start_at')->map(function (array $slot): string {
                $time = ($slot['start_time'] ?? '').(($slot['end_time'] ?? null) !== null ? "–{$slot['end_time']}" : '');
                if (($slot['is_appointment'] ?? false) || in_array($slot['type'] ?? null, ['personal', 'appointment'], true)) {
                    return "- {$time}: one of their own plans (not shown to you)";
                }
                $kind = SpotCategory::tryFrom((string) ($slot['category'] ?? ''))?->label() ?? ucfirst(str_replace('_', ' ', (string) ($slot['category'] ?? '')));

                return "- {$time}: \"{$slot['name']}\" ({$kind})".(filled($slot['veedel'] ?? null) ? " in {$slot['veedel']}" : '').self::hoursThatDay($slot);
            })->implode("\n");
    }

    /** A stop's listed hours on its plan's day ("listed open 12:00–17:00 that day"), so "is it open then?" is answered from them. */
    private static function hoursThatDay(array $slot): string
    {
        $hours = $slot['place_facts']['hours'] ?? [];
        if (($slot['type'] ?? null) !== 'spot') {
            return '';
        }
        if (($hours['status'] ?? null) !== 'known' || ! is_array($hours['parsed'] ?? null)) {
            return '; opening hours not listed';
        }
        $day = CarbonImmutable::parse((string) $slot['start_at'])->setTimezone('Europe/Berlin');
        $intervals = $hours['parsed'][strtolower($day->format('D'))] ?? [];
        if ($intervals === [] || OpeningHoursParser::closedOn($hours['raw'] ?? null, $day)) {
            return '; listed closed that day';
        }

        return '; listed open '.collect($intervals)->map(fn (array $i): string => "{$i[0]}–{$i[1]}")->implode(', ').' that day';
    }

    /**
     * One place they name (coordinator, 24 Oct 2026: "Is Café Wohnraum good for working on a
     * laptop?" answered with six other cafés): its card and what's listed of it, by Expadu's place
     * finder; which, when several have that name; and that none has, when none has.
     */
    private function findPlace(array $input): string
    {
        $named = trim((string) ($input['name'] ?? ''));
        $area = is_string($input['area'] ?? null) && trim($input['area']) !== '' ? trim($input['area']) : null;
        // "TK Maxx near Neumarkt": near a square, stop or place, so in its Veedel (places' matrix, 24 Oct 2026).
        if ($area === null && preg_match('/^(.+?)\s+(?:near|by|at|around)\s+(.+)$/iu', $named, $m) === 1) {
            [$named, $area] = [trim($m[1]), trim($m[2])];
        }
        $nearPlace = $area !== null && PlanDay::areas([$area]) === [] ? $this->near($area) : null;
        $area = $nearPlace['veedel'] ?? $area;
        if (mb_strlen($named) < 3) {
            return 'Say which place.';
        }
        $near = $this->location->hasOrigin() ? new GeoPoint((float) $this->location->lat, (float) $this->location->lng) : null;
        $found = array_values(array_filter(app(Destinations::class)->find($named, $area, $near), fn (array $p): bool => $p['spot'] !== null));
        $in = fn (array $p): string => $p['name'].($p['area'] !== null ? " in {$p['area']}" : '');
        if ($found === []) {
            return $this->line('n'.(++$this->count), "No place called \"{$named}\"".($area !== null ? " in {$area}" : '').' is among Expadu\'s places.');
        }
        if (count($found) > 1) {
            $id = 'c'.(++$this->count);
            $this->cards[$id] = ['type' => 'choices', 'text' => "There's more than one {$named}. Which do you mean?",
                'options' => array_map(fn (array $p): array => ['label' => $p['name'].($p['area'] !== null ? " · {$p['area']}" : ''), 'prompt' => $in($p)], $found)];

            return $this->line($id, "More than one place is called \"{$named}\": ".implode('; ', array_map($in, $found)).'. Ask which.');
        }
        [$lat, $lng] = $near !== null ? [$near->lat, $near->lng] : [CandidateRepository::COLOGNE_LAT, CandidateRepository::COLOGNE_LNG];
        // The fact subqueries trip Postgres's JIT (3–4 s); see CandidateRepository::spotCandidates.
        $c = DB::transaction(function () use ($found, $lat, $lng): ?Candidate {
            DB::statement('SET LOCAL jit = off');

            return $this->catalogue->byIds(["spot:{$found[0]['spot']}"], $this->now, $lat, $lng)[0] ?? null;
        });
        if (! $c instanceof Candidate) {
            return $this->line('n'.(++$this->count), "\"{$in($found[0])}\" isn't listed any more.");
        }
        $this->places[$c->id] = $c;
        $this->measured[$c->id] = $this->location->hasOrigin() && $this->location->source !== LocationSource::Area;
        $this->from[$c->id] = ['name' => $this->origin(), 'lat' => $lat, 'lng' => $lng];
        $groups = app(FacilityGroups::class);
        $has = FacilityGroups::summary($groups->counts([(int) substr($c->id, 5)])[(int) substr($c->id, 5)] ?? []);
        $use = self::publicUse($c);
        $laptop = self::laptopSaid($c);

        // Near a place they named: how far from it, so "near Neumarkt" is said as found.
        $from = $nearPlace === null ? '' : ' About '.self::distance(111.32 * sqrt(($c->lat - $nearPlace['lat']) ** 2
            + (($c->lng - $nearPlace['lng']) * cos(deg2rad($c->lat))) ** 2))." from {$nearPlace['name']}.";

        return $this->line($c->id, self::placeText($c, $this->now, true, (bool) $this->user->needs_step_free, $has, $use['value'] !== 'unknown' || $use['free'] !== 'unknown').$from
            // Laptop work, where it's listed or the kind of place is one people work in.
            .($laptop !== 'Laptop work isn\'t listed yet.' || in_array($c->category, ['cafe', 'library', 'coworking'], true) ? ' '.$laptop : '')
            .(isset($this->earlier['spot'][(int) substr($c->id, 5)]) ? ' (The same place as shown before in this conversation.)' : ''));
    }

    /**
     * A square, stop or place they name ("near Neumarkt"): where it is and its Veedel, as a KVB stop
     * first, else by Expadu's place finder; null when it's none of those.
     *
     * @return array{name: string, lat: float, lng: float, veedel: ?string}|null
     */
    private function near(string $named): ?array
    {
        $stops = app(FavouriteStops::class);
        $station = $stops->stations($named);
        $point = count($station) === 1 ? $stops->point($station[0]) : null;
        if ($point !== null) {
            return ['name' => $station[0], ...$point, 'veedel' => app(UserLocationService::class)->veedelAt($point['lat'], $point['lng'])];
        }
        $from = $this->location->hasOrigin() ? new GeoPoint((float) $this->location->lat, (float) $this->location->lng) : null;
        $place = app(Destinations::class)->find($named, null, $from)[0] ?? null;

        return $place === null ? null : ['name' => $place['name'], 'lat' => $place['point']->lat, 'lng' => $place['point']->lng,
            'veedel' => $place['area'] ?? app(UserLocationService::class)->veedelAt($place['point']->lat, $place['point']->lng)];
    }

    private function searchPlaces(array $input): string
    {
        $asked = $this->clock($input['open_at'] ?? null, $this->now);
        $at = $asked ?? $this->now;
        // "Open now" or "open at 19:30": only places whose listed hours say so (OpeningAnswer).
        $mustBeOpen = $asked !== null || ($input['open_now'] ?? false) === true;
        // All of Cologne ("generally in Cologne"): no area or place to look around (owner, 26 Oct 2026).
        $whole = ($input['whole_city'] ?? false) === true;
        $named = ! $whole && is_string($input['area'] ?? null) && trim($input['area']) !== '' ? trim($input['area']) : null;
        $nearName = ! $whole && is_string($input['near'] ?? null) && trim($input['near']) !== '' ? trim($input['near']) : null;
        $areas = $named !== null ? PlanDay::areas([$named]) : [];
        // A square, stop or place named as the area ("Neumarkt"): around it, never refused (owner's chat, 24 Oct 2026);
        // a Veedel or the centre named as near is the area.
        if ($named !== null && $areas === []) {
            [$nearName, $named] = [$nearName ?? $named, null];
        }
        if ($nearName !== null && PlanDay::areas([$nearName]) !== []) {
            [$named, $areas, $nearName] = [$named ?? $nearName, $areas !== [] ? $areas : PlanDay::areas([$nearName]), null];
        }
        $around = $nearName !== null ? $this->near($nearName) : null;
        if ($nearName !== null && $around === null) {
            return $this->line('n'.(++$this->count), "No Veedel, KVB stop or place called \"{$nearName}\" is known.");
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
        $work = ($input['to_work'] ?? false) === true;
        // All of Cologne: from the middle of the city out, or, to work, wherever it lists most for that, nearest them after.
        $fromCentre = $whole && ! $work;
        [$lat, $lng] = match (true) {
            $around !== null => [$around['lat'], $around['lng']],
            $middle?->lat !== null => [(float) $middle->lat, (float) $middle->lng],
            ! $fromCentre && $this->location->hasOrigin() => [(float) $this->location->lat, (float) $this->location->lng],
            default => [CandidateRepository::COLOGNE_LAT, CandidateRepository::COLOGNE_LNG],
        };
        // How far a place is, shown on its card, only from where the person really is or starts, or the place they named.
        $measured = $around !== null || (! $fromCentre && $middle?->lat === null && $this->location->hasOrigin() && $this->location->source !== LocationSource::Area);
        // Asked for one kind or a sport: up to the whole list of it; for several ("a place to work": cafés,
        // libraries, coworking), a share of each; for anything, two of a kind, for variety. Across the city
        // to work: those listing most for it, whichever kind (owner, 26 Oct 2026: two cafés of 41 with Wi-Fi).
        $perKind = match (true) {
            $whole && $work => self::SHOWN,
            count($kinds) > 1 => max(2, intdiv(self::SHOWN, count($kinds))),
            $kinds !== [] || $activity !== null => self::SHOWN,
            default => 2,
        };
        $stepFree = $this->user->needs_step_free || ($input['step_free'] ?? false) === true;
        // Facts a place must list ("wifi", "diet:vegan"): few places list them, so the search reads further.
        $features = array_values(array_unique(array_filter(is_array($input['features'] ?? null) ? $input['features'] : [], fn (mixed $f): bool => is_string($f) && PlaceAmenities::valid($f))));
        $free = ($input['free'] ?? false) === true;
        // Few places list hours, facts, a playground's goals or what they say of laptops: for those, the search reads further.
        $deep = $mustBeOpen || $features !== [] || $plays !== null || $work;
        $stepFree = $stepFree || in_array('wheelchair', $features, true);
        $constraints = new Constraints(
            windowStart: $at,
            windowEnd: $at->addHours(2)->min($at->endOfDay()),
            areas: $areas,
            categories: $kinds,
            activities: array_values(array_filter([$activity])),
            moods: array_values(array_filter([is_string($input['mood'] ?? null) ? $input['mood'] : null])),
            // Their own step-free need, from their settings (never told to the AI), or asked for in words.
            needs: $stepFree ? ['step_free'] : [],
            features: $features,
        );
        // When it must be open then, or list a fact, more of each kind are read: most have no listed hours or facts.
        $nearest = collect($whole && $work ? $this->catalogue->workSpots($constraints, $lat, $lng)
            : $this->catalogue->nearestSpots($constraints, $lat, $lng, $deep ? max($perKind * 4, 12) : $perKind, $deep ? self::SHOWN * 8 : self::SHOWN * 3));
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
                && (($input['indoors'] ?? false) !== true || self::setting($c) === 'indoors')
                // To work there: never a place that says no laptops then (places session's website facts).
                && (! $work || self::laptopsWelcome($c, $at) !== false)
                // Free (coordinator's call, 23 Oct 2026): public use, by the places session's public_use. Only a
                // place listed public, or with nothing listed, stays; one restricted (a school's, a daycare's, as
                // their data marks it) or listing a fee is left out.
                && (! $free || self::freeToUse($c)))
            ->values();
        // Listed as closed then: never offered; asked for open: only those whose listed hours say so.
        // Open: listed open, or an open-air place with no set hours (a public pitch), after those; never one listed closed.
        // The same question again (owner, 23 Oct 2026): the places shown before come back first, nearest
        // first among them, so the cards are the ones it showed.
        $again = $this->askedAgain();
        $shownBefore = fn (Candidate $c): bool => isset($this->earlier['spot'][$park($c)]) || isset($this->earlier['spot'][(int) substr($c->id, 5)]);
        $rank = fn (Candidate $c): float => ($c->distanceKmFromOrigin ?? INF) + ($park($c) === (int) substr($c->id, 5) && ($c->placeFacts['generic_name'] ?? false) === true ? 0.75 : 0)
            + ($again && ! $shownBefore($c) ? 1000 : 0)
            + ($plays !== null && ! in_array($plays, array_map('trim', explode(';', (string) ($c->placeFacts['practical']['sport']['value'] ?? ''))), true) ? 1.5 : 0)
            + ($work && ! self::workFriendly($c) ? 0.5 : 0)
            // Across the city, to work: what it lists for that before how far it is.
            - ($whole && $work ? 100 * self::workScore($c, $at) : 0)
            // Listed open first; asked for free ones, nearest first (coordinator, 24 Oct 2026: the cards
            // show distances, never why one further on comes first).
            + ($mustBeOpen && OpeningAnswer::isOpenAt($c, $at) !== true ? 0.5 : 0);
        $found = $matches->filter(fn (Candidate $c): bool => $mustBeOpen ? OpeningAnswer::isOpenAt($c, $at) === true || ($c->outdoor && OpeningAnswer::isOpenAt($c, $at) === null)
            : OpeningAnswer::isOpenAt($c, $at) !== false)
            // Nearest first, and a place with its own name (a park's) before a generic one at a like distance.
            ->sortBy($rank)
            // One per place: two pitches in one park are the park once, its nearest; a chain's
            // branches in different Veedel stay apart.
            ->unique(fn (Candidate $c): string => $park($c) !== (int) substr($c->id, 5) ? 'park:'.$park($c).'|'.$part($c) : mb_strtolower(self::name($c)).'|'.$c->veedel)
            ->groupBy('category')->flatMap(fn ($kind) => $kind->take($perKind))
            ->sortBy($rank)
            ->take(self::SHOWN)->values();
        $found = $this->asParks($found, $parents, $large, $at, $lat, $lng);
        $where = match (true) {
            $whole => 'across Cologne',
            $around !== null => "near {$around['name']}",
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

        // The cards it will show were all shown before (asked again): said so, with the nearest as it's
        // listed, so the answer restates it rather than only offering something else (owner, 23 Oct 2026).
        // Asked word for word again, those shown come first: the ones shown before, then any new.
        $before = $found->take(Assistant::PLACES_SHOWN)->takeWhile(fn (Candidate $c): bool => isset($this->earlier['spot'][(int) substr($c->id, 5)]))->count();
        $same = $before > 0 && ($before === min(Assistant::PLACES_SHOWN, $found->count()) || $again)
            ? $this->line('s'.(++$this->count), 'The same places as shown before'.($before < min(Assistant::PLACES_SHOWN, $found->count()) ? ' (the first '.$before.'; the others are new)' : '').'. The nearest: '
                .self::placeText($found->first(), $at, $at->equalTo($this->now), $stepFree, $this->parks[$found->first()->id]['has'] ?? null, $free, $this->parks[$found->first()->id]['matched'] ?? null))."\n"
            : '';

        // Nearest first ("closer to me" means the first ones), but no distances: where they are stays here.
        // Asked for free ones (coordinator, 24 Oct 2026): free only where a source lists no fee, else "public, no fee listed".
        $fee = ! $free ? '' : ($found->every(fn (Candidate $c): bool => self::publicUse($c)['free'] === 'yes') ? 'Each lists free use. '
            : 'Call a place free only where its line says "Free to use (listed)"; the others are "public, no fee listed", also in your first sentence ("These public pitches, no fee listed…", never "These free pitches"). '
            // Nearest first, a place with nothing listed may come among them: never called public.
            .($found->contains(fn (Candidate $c): bool => self::publicUse($c)['value'] === 'unknown') ? 'Where a line says "Fee not listed", nothing is listed of its fee or who may use it: don\'t call that one public. ' : ''));

        return $same.$fee.($features !== [] ? 'Only places that list '.self::featureWords($features).'. ' : '').match (true) {
            // Never all there is: the city's places that list most for working, or the nearest the middle.
            $whole => 'Across Cologne, '.($work ? 'those listing most for working (laptops welcome, Wi-Fi, sockets) first, then nearest them; not every place in the city:' : 'from the middle of the city out; not every place in the city:'),
            $where !== null => ucfirst($where).($named !== null && ! in_array(mb_strtolower($named), array_map('mb_strtolower', $areas), true) ? " (\"{$named}\")" : '').', nearest first:',
            $this->location->hasOrigin() => 'Nearest to the person first:',
            default => "Across Cologne (where they are isn't known):",
        }."\n".$found->map(function (Candidate $c) use ($at, $measured, $lat, $lng, $areas, $around, $stepFree, $features, $work, $free): string {
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
                $around !== null => $around['name'],
                $areas === PlanDay::CENTRE => 'City centre',
                $areas !== [] => implode(', ', $areas),
                default => $this->origin(),
            }, 'lat' => $lat, 'lng' => $lng];

            // Step-free said only when they need it or asked (owner's preview, 23 Oct 2026: never unasked).
            return $this->line($c->id, self::placeText($c, $at, $at->equalTo($this->now), $stepFree, $this->parks[$c->id]['has'] ?? null, $free, $this->parks[$c->id]['matched'] ?? null)
                // To work there: what its sources say of laptops, and that nothing is listed when nothing is.
                .($work ? ' '.self::laptopSaid($c) : '')
                // Near a place they named: how far from it, so "near Neumarkt" is said as found.
                .($around !== null && $c->distanceKmFromOrigin !== null ? ' About '.self::distance($c->distanceKmFromOrigin)." from {$around['name']}." : '')
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
                // What the pitch lists (public, free) holds for the park answered for it, when the park lists none.
                'access' => $park->access === 'unknown' ? $c->access : $park->access,
                'costTier' => $park->costTier === 'unknown' ? $c->costTier : $park->costTier,
                'placeFacts' => [...$park->placeFacts, 'public_use' => self::publicUse($park)['value'] === 'unknown' ? self::publicUse($c) : self::publicUse($park)],
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

    /**
     * Indoors or outdoors as far as it's known: its own mapped setting (indoor, covered), else its kind
     * when that settles it (a park is outdoors, a museum a building), else nothing (a tourism=attraction
     * may be a statue in the street: one was called "an indoor attraction", 24 Oct 2026).
     */
    private static function setting(Candidate $c): ?string
    {
        $practical = $c->placeFacts['practical'] ?? [];
        $mapped = fn (string $key): ?string => ($practical[$key]['status'] ?? null) === 'known' ? strtolower((string) ($practical[$key]['value'] ?? '')) : null;

        return match (true) {
            in_array($mapped('indoor'), ['yes', 'true', '1'], true) || in_array($mapped('covered'), ['yes', 'true', '1'], true) => 'indoors',
            $c->outdoor || in_array($mapped('indoor'), ['no', 'false', '0'], true) => 'outdoors',
            $c->type === 'spot' && ! in_array($c->category, self::SETTING_UNSAID, true) => 'indoors',
            default => null,
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
            // OSM's conditional form: "no @ (Sa-Su)" bans them those days; "limited @ (Sa-Su)" allows them
            // then only in some areas (Treibgut: the library), so the place is kept but not counted as welcome.
            $condition = (string) ($practical['laptops:conditional']['value'] ?? '');
            if (! self::onDays($condition, $at)) {
                return true;
            }

            return self::conditionKind($condition) === 'limited' ? null : false;
        }

        return $laptops === 'yes' ? true : null;
    }

    /** What a laptops:conditional says applies on its days: "no" (the default, and the older plain days) or "limited". */
    private static function conditionKind(string $condition): string
    {
        return preg_match('/^\s*limited\s*@/i', $condition) === 1 ? 'limited' : 'no';
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
            $said[] = match (true) {
                $laptops === 'yes' => $who === 'It lists' ? 'It lists laptops as welcome.' : 'Its website says laptops are welcome.',
                $laptops === 'no' => $who === 'It lists' ? 'It lists laptops as not allowed.' : 'Its website says laptops aren\'t allowed.',
                self::conditionKind($days) === 'limited' => "{$who} laptops are only allowed in some areas {$when}.",
                default => "{$who} laptops aren't allowed {$when}.",
            };
            // Its English line for the other days or conditions, translated only from its own words (places
            // session), said as being of laptops, so a sentence saying it ("welcome on weekdays") holds.
            if (is_string($practical['laptops']['note'] ?? null) && trim($practical['laptops']['note']) !== '') {
                $said[] = 'Of laptops, it also says: "'.trim($practical['laptops']['note']).'"';
            }
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

    /**
     * Who may use a place and whether it's free (PlaceFacts::publicUse): the city's register of public
     * play areas, an access tag, or a fee=no; unknown when nothing says.
     *
     * @return array{value: string, free: string, basis: ?string, source_url: ?string}
     */
    /** Free to use as its data says: public, or nothing listed of who may, and no fee listed. */
    private static function freeToUse(Candidate $c): bool
    {
        return in_array(self::publicUse($c)['value'], ['yes', 'unknown'], true) && self::publicUse($c)['free'] !== 'no';
    }

    public static function publicUse(Candidate $c): array
    {
        $use = $c->placeFacts['public_use'] ?? null;

        return is_array($use) ? $use : ['value' => 'unknown', 'free' => 'unknown', 'basis' => null, 'source_url' => null];
    }

    /** A place to work at: it lists Wi-Fi, sockets or laptops welcome. */
    /** How much a place lists for working there: laptops welcome then (its rule, or people's answers), Wi-Fi, sockets. */
    private static function workScore(Candidate $c, CarbonImmutable $at): int
    {
        $practical = $c->placeFacts['practical'] ?? [];

        return (self::laptopsWelcome($c, $at) === true ? 4 : 0) + (PlaceAmenities::has($practical, 'wifi') ? 2 : 0)
            + (mb_strtolower((string) ($practical['power_supply']['value'] ?? '')) === 'yes' ? 1 : 0);
    }

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
        // A day they already have a plan for keeps it (owner, 24 Oct 2026: "Find us two activities" rewrote
        // his Sunday): a new plan only when they ask for one instead, in their own words.
        $day = PlanDay::dayOf($input['day'] ?? null, $this->now);
        $kept = $day === null ? null : app(WorkspaceStore::class)->plan($this->user, $day->toDateString());
        if (($kept['slots'] ?? []) !== [] && ! $this->theySaid((string) ($input['replacing'] ?? ''))) {
            return $this->line('n'.(++$this->count), 'They have a plan for '.$day->isoFormat('dddd').' already, and it stays as it is. To suggest more for it, use suggest_for_day; '
                .'to change it, add_to_day, remove_from_day, swap_in_day or move_in_day, for the stops they named only. Only when they ask for a whole new plan instead, plan_day again with replacing (their words asking for it).');
        }
        $planned = app(PlanDay::class)->plan($this->user, $input, $this->now);
        if (is_string($planned)) {
            return $planned;
        }
        $id = 'plan'.(++$this->count);
        $this->cards[$id] = ['type' => 'plan', 'token' => $planned['token'],
            // How many stops it has, their own appointments aside: "two museums" may say so.
            'stops' => count(array_filter($planned['plan']['slots'], fn (array $slot): bool => ! ($slot['is_appointment'] ?? false))),
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
                    'item' => ['type' => 'string', 'description' => 'its id in [brackets] from your lookups ("spot:…", "e…@…"), else a place\'s name'],
                    'area' => ['type' => 'string', 'description' => 'a Veedel they named with the place, optional'],
                    'day' => $day,
                    'start' => ['type' => 'string', 'description' => 'HH:MM, optional'], 'end' => ['type' => 'string', 'description' => 'HH:MM, optional'],
                ], 'required' => ['item']]],
            ['name' => 'remove_from_day', 'description' => 'Takes something off their plans for a day, by its name as they said it.',
                'input_schema' => ['type' => 'object', 'properties' => ['item' => ['type' => 'string'], 'day' => $day], 'required' => ['item']]],
            ['name' => 'swap_in_day', 'description' => 'Swaps something in their plans for a day for another, at the same time, with Undo. For a place they name, give it as with. For something of a kind ("replace Rheingarten with something indoors", "swap it for a museum"), leave out with and give the kind: the swap finds the one that fits that time best and makes it; don\'t search first.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'item' => ['type' => 'string', 'description' => 'what\'s in their day now, as they said it'],
                    'with' => ['type' => 'string', 'description' => 'the new one\'s id from your lookups, else its name; leave out for something of a kind'],
                    'kinds' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'without with: the kinds they asked for (museum, cafe, restaurant…); leave out for the same kind as the stop'],
                    'indoors' => ['type' => 'boolean', 'description' => 'without with: only indoor places'],
                    'free' => ['type' => 'boolean', 'description' => 'without with: only places free to use'],
                    'day' => $day,
                ], 'required' => ['item']]],
            ['name' => 'move_in_day', 'description' => 'Moves one thing in their plans for a day to another time, or makes it shorter or longer: that one only, nothing else moves.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'item' => ['type' => 'string', 'description' => 'what\'s in their day now, as they said it'],
                    'day' => $day,
                    'start' => ['type' => 'string', 'description' => 'HH:MM it starts at now'],
                    'end' => ['type' => 'string', 'description' => 'HH:MM it ends at; leave out to keep how long it is'],
                ], 'required' => ['item', 'start']]],
            ['name' => 'clear_day', 'description' => 'Clears their plans for a day.', 'input_schema' => ['type' => 'object', 'properties' => ['day' => $day]]],
            ['name' => 'save_event', 'description' => 'Saves a listing for later (or takes it off their saved events), as the event\'s Save does.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'event' => ['type' => 'string', 'description' => 'its id in [brackets] from a listing line of your lookups ("e…@…"), else its title'],
                    'saved' => ['type' => 'boolean', 'description' => 'false to take it off'],
                ], 'required' => ['event']]],
            ['name' => 'remind_me', 'description' => 'Sets a reminder for a listing (an hour or three hours before, or that morning), or turns it off.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'event' => ['type' => 'string', 'description' => 'its id in [brackets] from a listing line of your lookups ("e…@…"), else its title'],
                    'when' => ['type' => 'string', 'enum' => ['1h', '3h', 'morning']],
                    'on' => ['type' => 'boolean', 'description' => 'false to turn it off'],
                ], 'required' => ['event']]],
            ['name' => 'save_stop', 'description' => 'Stars a KVB stop as one of their favourite stops in Travel (or takes it off).',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'stop' => ['type' => 'string', 'description' => 'the stop as they said it'],
                    'saved' => ['type' => 'boolean', 'description' => 'false to take it off'],
                ], 'required' => ['stop']]],
            ['name' => 'set_travel_prefs', 'description' => 'Sets how they travel by default (by bus and train, by bike, on foot, or the fastest way) and whether they have a Deutschlandticket, as Travel keeps them. Only what they said.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'mode' => ['type' => 'string', 'enum' => ['transit', 'bike', 'walk', 'fastest']],
                    'deutschlandticket' => ['type' => 'boolean'],
                ]]],
            ['name' => 'plan_trip_for_later', 'description' => 'Adds the way to a plan on their day (today or tomorrow) to that plan, from where they are, with a reminder five minutes before they need to leave, as Travel\'s Add to plan does. For a place not on their day yet, add it with add_to_day first.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'plan' => ['type' => 'string', 'description' => 'the plan as they named it, or "next" for their next one'],
                    'remind' => ['type' => 'boolean', 'description' => 'false for no reminder'],
                ]]],
            ['name' => 'mute_alerts', 'description' => 'Mutes alerts about a KVB line\'s disruptions and delays until midnight or for a week (or turns them back on).',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'line' => ['type' => 'string', 'description' => 'the line, e.g. "13" or "S11"'],
                    'for' => ['type' => 'string', 'enum' => ['today', 'week']],
                    'on' => ['type' => 'boolean', 'description' => 'false to turn the alerts back on'],
                ], 'required' => ['line']]],
            ['name' => 'dismiss_alert', 'description' => 'Dismisses their alerts on the Alerts page, as its Dismiss does: all of them, or those of one kind. You\'re told only how many.',
                'input_schema' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => ['all', 'transit', 'city', 'events']]]]],
            ['name' => 'set_notifications', 'description' => 'Turns a kind of notification on or off, as Settings › Notifications does: transit (disruptions on their lines and trips), checklist (Paperwork deadlines), events (event reminders), weather, rhine (Rhine flood alerts), digest (the weekly digest). Push on this device needs their own tap in the browser: for that, open_in_app "push_notifications".',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'kind' => ['type' => 'string', 'enum' => array_keys(SettingsActions::NOTIFICATIONS)],
                    'on' => ['type' => 'boolean'],
                ], 'required' => ['kind', 'on']]],
            ['name' => 'set_needs', 'description' => 'Sets whether they need step-free ways (lifts, no stairs): Travel and Composer then plan only those.',
                'input_schema' => ['type' => 'object', 'properties' => ['step_free' => ['type' => 'boolean']], 'required' => ['step_free']]],
            ['name' => 'update_profile', 'description' => 'Changes their name, city, situation or German level on their profile. Never their email: open_in_app "change_email" for that.',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'field' => ['type' => 'string', 'enum' => array_keys(SettingsActions::PROFILE)],
                    'value' => ['type' => 'string', 'description' => 'as they said it; a situation as non_eu_employee, eu_employee, student, freelancer, family_reunification, digital_nomad or other; a German level as none or a1 to c2'],
                ], 'required' => ['field', 'value']]],
            ['name' => 'open_in_app', 'description' => 'For what Composer never does itself because it can\'t be undone or is theirs to decide (deleting their Paperwork record, stopping its processing, withdrawing the AI permission or the one to read their Paperwork, deleting their account, turning personalisation or location sharing off, which deletes what was kept) or needs their own tap (push notifications on this device, changing their email): shows the exact place that does it, and why. Never to ask for a permission: a card does that by itself. Say one short line; the card says the rest.',
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
        // A listing it looked up or this conversation showed, said by its title ("remind me about the jazz
        // night"): that one, when one title holds what was said, or the other way round.
        if (is_string($input['event'] ?? null) && preg_match('/^e\d+(@\d+)?$/', $input['event']) !== 1) {
            $said = Destinations::key((string) $input['event']);
            $listings = [];
            foreach ($this->events as $id => $occurrence) {
                $listings[] = [$id, (string) ($occurrence['event']->title_en ?: $occurrence['event']->title)];
            }
            foreach ($this->earlier['event'] as $id => $title) {
                $listings[] = ["e{$id}", (string) $title];
            }
            $shown = array_values(array_unique(array_column(array_filter($listings, fn (array $l): bool => $said !== ''
                && (str_contains(Destinations::key($l[1]), $said) || str_contains($said, Destinations::key($l[1])))), 0)));
            if (count($shown) === 1) {
                $input['event'] = $shown[0];
            }
        }
        // A place this conversation showed, said by its name ("save Café Nova", or "spot:Café Nova"
        // with its id's prefix): that one.
        foreach (['place', 'item', 'with'] as $field) {
            if (is_string($input[$field] ?? null) && preg_match('/^(spot:\d+|e\d+@\d+)$/', $input[$field]) !== 1) {
                $input[$field] = (string) preg_replace('/^spot:\s*(?=\D)/', '', $input[$field]);
                $shown = array_keys(array_filter($this->earlier['spot'], fn (string $name): bool => Destinations::key($name) === Destinations::key((string) $input[$field])));
                if (count($shown) === 1) {
                    $input[$field] = "spot:{$shown[0]}";
                }
            }
        }
        // Where they are goes to the action from the request itself, never from what the AI wrote.
        $input = array_filter($input, fn (mixed $key): bool => ! str_starts_with((string) $key, '_'), ARRAY_FILTER_USE_KEY);
        if ($action === 'plan_trip_for_later') {
            $input['_from'] = $this->location;
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
    /** @param  ?string  $matched  for a park answered for a facility in it, that facility ("Football pitch"), which its public use is about */
    public static function placeText(Candidate $c, ?CarbonImmutable $at = null, bool $now = true, bool $stepFree = false, ?string $has = null, bool $free = false, ?string $matched = null): string
    {
        $kind = self::kindOf($c);
        // Open only from its listed hours; assumed hours are never "open" (OpeningAnswer).
        $open = $at === null ? null : OpeningAnswer::isOpenAt($c, $at);
        $hours = match (true) {
            $open === true => ' Open '.($now ? 'now' : 'at '.$at->setTimezone('Europe/Berlin')->format('H:i')).($c->closesAt !== null ? ', until '.$c->closesAt->setTimezone('Europe/Berlin')->format('H:i').' today' : '').'.',
            // A public pitch or park has no opening hours to list: it's said as what it is.
            $open === null => $c->outdoor ? ' Open-air, no set hours listed.' : ' Opening hours not listed.',
            default => '',
        };

        // What its listing or website lists; Wi-Fi from people's answers is said as theirs (laptopSaid).
        $lists = array_values(array_diff(PlaceAmenities::listed($c->placeFacts['practical'] ?? []),
            ($c->placeFacts['practical']['internet_access']['provider'] ?? null) === 'people' ? ['Wi-Fi'] : []));

        $setting = self::setting($c);

        return '"'.self::name($c).'" ('.$kind.($setting !== null ? ", {$setting}" : '').')'.($c->veedel !== null ? " in {$c->veedel}" : '').'.'
            .($has !== null ? ' Has: '.$has.'.' : '')
            .($lists !== [] ? ' Lists: '.implode(', ', $lists).'.' : '')
            .$hours
            // Who may use it and whether it's free, by its basis (public_use): never more than is listed.
            .match (true) {
                self::publicUse($c)['free'] === 'yes' => ' Free to use (listed).',
                $free && self::publicUse($c)['value'] === 'yes' && self::publicUse($c)['basis'] === 'city_register' => ' Public '.mb_strtolower($matched ?? self::kindOf($c)).' (City of Cologne register), no fee listed.',
                $free && self::publicUse($c)['value'] === 'yes' => ' Public, no fee listed.',
                $free => ' Fee not listed.',
                default => '',
            }
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
