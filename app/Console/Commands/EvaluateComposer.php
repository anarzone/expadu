<?php

namespace App\Console\Commands;

use App\Ai\LlmCalls;
use App\Bureaucracy\Facts\ConfirmedFactView;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Composer\Answers\AroundCheck;
use App\Composer\Assistant\Ask;
use App\Composer\Assistant\Assistant;
use App\Composer\Assistant\AssistantTools;
use App\Composer\Assistant\PlanDay;
use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Composer\Eval\PlanChecks;
use App\Composer\PlanSlot;
use App\Composer\PrivatePlanCache;
use App\Composer\WorkspaceStore;
use App\Enums\AlertType;
use App\Enums\Situation;
use App\Enums\SpotCategory;
use App\Models\Alert;
use App\Models\ComposerWorkspace;
use App\Models\EventBookmark;
use App\Models\EventReminder;
use App\Models\FavouriteStop;
use App\Models\PlannedTrip;
use App\Models\ProcessingOptIn;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use App\Personalisation\Pseudonym;
use App\Places\PlaceAmenities;
use App\Places\PlaceFacts;
use App\Privacy\ProcessingPurpose;
use App\Services\MuteService;
use App\Services\WeatherService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * The Composer evaluation: runs a fixed set of requests (tests/Fixtures/
 * composer-eval/requests.json) through Composer's one entry (Assistant\Ask), as
 * the page sends them, for one synthetic user per persona, each in a fresh
 * conversation (a follow-up after its earlier turns), and checks every plan
 * against the database (PlanChecks). Each request runs at its weekday and time
 * in the coming week, so events are real, with the scenario's weather. Gates
 * must stay at zero: the plan gates (PlanChecks) and, for every answer, no dead
 * end, nothing the AI says that what it looked at doesn't, no "because" its
 * taste doesn't back, and nothing turned down shown again.
 *
 * Writes only to the synthetic users (composer-eval+…@example.invalid): their
 * workspace, composer runs, active plan and hand-off, as a person asking would.
 */
class EvaluateComposer extends Command
{
    protected $signature = 'composer:eval
        {--set=tests/Fixtures/composer-eval/requests.json : Request set}
        {--only= : Comma-separated request ids, persona names or scenario names}
        {--limit= : Run at most this many requests}
        {--week= : Monday of the week to run in (default: next Monday)}
        {--label=baseline : Name for the report file}
        {--effort= : The assistant\'s effort for this run (low, medium, high)}
        {--cohort= : Suffix for the synthetic users, so runs can go side by side}';

    protected $description = 'Evaluate Composer plans against a fixed request set';

    /** Every answer: never a dead end, never a fact what it looked at doesn't hold. */
    private const ANSWER_GATES = ['dead_end', 'invented', 'because_unbacked', 'repeated_after_refusal'];

    /** The fixture's keys about a plan (PlanChecks::expectations), as opposed to the answer. */
    private const ANSWER_EXPECT = ['routed' => true, 'around' => true, 'answer_kind' => true, 'open' => true, 'min_places' => true, 'places_in' => true, 'place_kinds' => true,
        'kind_is' => true, 'open_only' => true, 'distinct_names' => true, 'says' => true, 'no_leave_out' => true, 'leave_out' => true, 'places_has' => true, 'no_step_free' => true,
        'plan_unchanged' => true, 'proposals' => true, 'plan_has' => true, 'plan_lacks' => true, 'plan_stops' => true, 'not_before' => true, 'new_stop_kind' => true, 'places_list' => true,
        'all_rows' => true, 'not_says' => true, 'acted' => true, 'changed' => true, 'handoff' => true, 'no_change' => true, 'places_not' => true, 'first_names_place' => true,
        'choices' => true, 'proposed_to' => true];

    /** A request that asks for a plan or an order of stops (the eval's oracle; Composer itself has no such rule). */
    private const ASKS_TO_PLAN = '/\b(plan|then|afterwards?|first|danach|zuerst|und dann)\b/iu';

    /** A follow-up that turns the last suggestions down (the eval's oracle; Composer itself has no such rule). */
    private const REFUSAL = '/^\s*(no+|nope|nah|meh|neither|none|boring)\b|\bnot (really|that|those|these|this|it|them|for me|interested|my thing)\b|\b(something|anything|somewhere) (else|different|new)\b|\b(another|other|different|new) (one|thing|idea|option|place|suggestion)s?\b|\binstead\b/iu';

    /** What the opted-in persona has saved and liked, as the nightly aggregate would write it. */
    private const TASTE = [['category', 'cafe', 12.0, 6], ['category', 'culture', 7.0, 4], ['category', 'nightclub', -4.0, 1],
        ['veedel', 'Ehrenfeld', 9.0, 6], ['daypart', 'evening', 8.0, 6]];

    private const SITUATIONS = [
        'student_budget' => Situation::Student,
        'remote_worker' => Situation::Freelancer,
        'toddler_family' => Situation::FamilyReunification,
        'school_family' => Situation::FamilyReunification,
    ];

    public function handle(PlanChecks $checks): int
    {
        if (app()->isProduction()) {
            $this->error('The evaluation creates synthetic users; it does not run in production.');

            return self::FAILURE;
        }

        $set = json_decode((string) file_get_contents(base_path((string) $this->option('set'))), true, flags: JSON_THROW_ON_ERROR);
        $requests = collect($set['requests'])
            ->when($this->option('only'), function ($all, string $only) {
                $wanted = array_map('trim', explode(',', $only));

                return $all->filter(fn (array $r): bool => array_intersect($wanted, [$r['id'], $r['persona'], explode('.', $r['id'])[1]]) !== []);
            })
            ->when($this->option('limit'), fn ($all, $limit) => $all->take((int) $limit))
            ->values();

        $monday = $this->option('week')
            ? CarbonImmutable::parse((string) $this->option('week'), 'Europe/Berlin')->startOfWeek()
            : CarbonImmutable::now('Europe/Berlin')->next(CarbonImmutable::MONDAY)->startOfDay();
        // One permit per request; the daily cap is for people, not this run.
        config(['bureaucracy_privacy.daily_limits.'.ProcessingPurpose::ComposerAssist->value => 100000]);
        if ($this->option('effort')) {
            config(['services.llm.effort' => (string) $this->option('effort')]);
        }

        $results = [];
        $bar = $this->output->createProgressBar($requests->count());
        foreach ($requests as $request) {
            $results[] = $this->runOne($request, $monday, $checks);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        $summary = $this->summarise($results);
        $this->report($summary, $results);

        $path = storage_path('app/composer-eval/'.$this->option('label').'-'.now()->format('Ymd-His').'.json');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode([
            'label' => $this->option('label'),
            'week_of' => $monday->toDateString(),
            'summary' => $summary,
            'results' => $results,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->line('Report: '.$path);

        return $summary['gates_total'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function runOne(array $request, CarbonImmutable $monday, PlanChecks $checks): array
    {
        $template = CarbonImmutable::parse($request['now']);
        $now = $monday->addDays($template->dayOfWeekIso - 1)->setTime($template->hour, $template->minute);
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
        $this->fixWeather((string) $request['weather']);

        $user = $this->user($request);
        $row = ['id' => $request['id'], 'text' => $request['text'], 'now' => $now->toIso8601String(), 'weather' => $request['weather']];
        // Each case in a conversation of its own: its earlier turns (a follow-up), then itself.
        ComposerWorkspace::query()->where('user_id', $user->id)->delete();
        try {
            // A plan the case starts from (the owner's Sunday): kept as a plan Composer made in this conversation.
            if (is_array($request['plan'] ?? null)) {
                $this->seedPlan($user, $request['plan'], $now);
            }
            foreach ($request['before'] ?? [] as $earlier) {
                $this->message($user, (string) $earlier, $request['origin'] ?? null, (bool) ($request['paperwork_permission'] ?? false));
            }
        } catch (Throwable $e) {
            return $row + ['error' => 'earlier turns: '.class_basename($e).': '.Str::limit($e->getMessage(), 200)];
        }
        if (($request['before'] ?? []) !== []) {
            $row['before'] = $request['before'];
        }
        $earlier = Assistant::history(collect(app(WorkspaceStore::class)->read($user)['conversations'])->first()['messages'] ?? [])['suggested'];
        $plansBefore = self::stops(app(WorkspaceStore::class)->read($user)['plans'] ?? []);

        $started = hrtime(true);
        try {
            [$assistant, $added] = $this->message($user, $request['text'], $request['origin'] ?? null, (bool) ($request['paperwork_permission'] ?? false));
        } catch (Throwable $e) {
            return $row + ['error' => 'ask: '.class_basename($e).': '.Str::limit($e->getMessage(), 200)];
        }
        $row['ask_ms'] = (int) ((hrtime(true) - $started) / 1e6);
        // Where the time went: each call and lookup, in order, and what's left (the workspace, the cards).
        $row['timing'] = array_map(fn (array $t): string => "{$t[0]} {$t[1]}", $assistant->timings);
        $row['timing'][] = 'rest '.($row['ask_ms'] - array_sum(array_column($assistant->timings, 1)));
        $row['tokens'] = ['input' => $assistant->usage['input'], 'output' => $assistant->usage['output'], 'calls' => $assistant->usage['calls']];
        $answer = collect($added)->first(fn (array $m): bool => ($m['request'] ?? null) === 'ask') ?? [];
        $plan = collect($added)->first(fn (array $m): bool => ($m['kind'] ?? null) === 'plan')['plan'] ?? null;
        $blocks = array_column($answer['blocks'] ?? [], 'type');
        $row['routed'] = $plan !== null ? 'compose' : 'answer';
        $row['said'] = array_filter(['kind' => $answer['kind'] ?? null, 'lead' => $answer['lead'] ?? null, 'text' => $answer['text'] ?? null,
            // A trip's ends and the lines it rides, to see the start named was kept.
            'trip' => isset($answer['trip']) ? [($answer['start']['name'] ?? 'here').' → '.($answer['destination']['name'] ?? '?'),
                collect($answer['trip']['journey']['legs'] ?? [])->pluck('line')->filter()->implode(', '),
                ...(isset(collect($answer['blocks'] ?? [])->firstWhere('type', 'trip')['major']) ? ['major: '.collect($answer['blocks'])->firstWhere('type', 'trip')['major']['title']] : [])] : null,
            'blocks' => $blocks, 'written' => $answer['written'] ?? null, 'without_ai' => $answer['without_ai'] ?? null,
            'looked' => $answer['read'] ?? null, 'events' => array_column($answer['events'] ?? [], 'title'), 'places' => array_column($answer['places'] ?? [], 'name'),
            'next' => array_column($answer['suggestions'] ?? [], 'label')], fn ($v) => $v !== null && $v !== []);
        // Written but nothing held: what it wrote, to see why.
        $dropped = array_values(array_filter(array_column($assistant->written['sentences'] ?? [], 'text'),
            fn (mixed $sentence): bool => is_string($sentence) && ! str_contains((string) ($answer['lead'] ?? ''), trim($sentence))));
        if ($dropped !== []) {
            $row['said']['dropped'] = $dropped;
        }

        // Where it should go ("What's on tonight?" → an answer), what it should show, and its plan.
        $expect = $request['expect'];
        $routing = isset($expect['routed']) ? ['routed' => $row['routed'] === $expect['routed']] : [];
        // An open request is answered or planned by the AI, never left to the fallback.
        $routing += ($expect['open'] ?? false) ? ['open' => ($answer['written'] ?? null) === 'ai'] : [];
        $routing += isset($expect['around']) ? ['around' => array_intersect($blocks, ['events', 'places']) !== []] : [];
        $routing += isset($expect['answer_kind']) ? ['answer_kind' => array_intersect((array) $expect['answer_kind'], $blocks) !== []] : [];
        // Places shown: at least so many, all in the areas asked about.
        $shownPlaces = $answer['places'] ?? [];
        $routing += isset($expect['min_places']) ? ['min_places' => count($shownPlaces) >= $expect['min_places']] : [];
        $routing += isset($expect['places_in']) ? ['places_in' => $shownPlaces !== [] && collect($shownPlaces)->every(fn (array $p): bool => in_array($p['area'] ?? null, $expect['places_in'], true))] : [];
        $routing += isset($expect['place_kinds']) ? ['place_kinds' => count(array_unique(array_column($shownPlaces, 'kind'))) >= $expect['place_kinds']] : [];
        $routing += isset($expect['kind_is']) ? ['kind_is' => $shownPlaces !== [] && collect($shownPlaces)->every(fn (array $p): bool => in_array($p['kind'] ?? null, (array) $expect['kind_is'], true))] : [];
        // "Open now": only places whose listed hours say so.
        $routing += ($expect['open_only'] ?? false) ? ['open_only' => collect($shownPlaces)->every(fn (array $p): bool => str_starts_with((string) ($p['hours'] ?? ''), 'Open'))] : [];
        $routing += ($expect['distinct_names'] ?? false) ? ['distinct_names' => count(array_unique(array_column($shownPlaces, 'name'))) === count($shownPlaces)] : [];
        // Asked again or narrowed, never turned down; step-free never said unasked.
        // The lookups as they ran: a leave-out without their words asking for others never takes effect.
        $leftOut = collect($assistant->timings)->contains(fn (array $t): bool => str_contains($t[0], '"leave_out_earlier":true'));
        $routing += ($expect['no_leave_out'] ?? false) ? ['no_leave_out' => ! $leftOut] : [];
        $routing += ($expect['leave_out'] ?? false) ? ['leave_out' => $leftOut] : [];
        // Places it must show (asked again, the nearest shown before).
        $routing += isset($expect['places_has']) ? ['places_has' => array_diff((array) $expect['places_has'], array_column($shownPlaces, 'name')) === []] : [];
        // Their plans as stored after the message: unchanged, holding or lacking stops by name, how many, and when.
        $plansAfter = self::stops(app(WorkspaceStore::class)->read($user)['plans'] ?? []);
        $stopsAfter = array_merge([], ...array_values($plansAfter));
        $named = fn (string $name): bool => collect($stopsAfter)->contains(fn (array $s): bool => str_contains(mb_strtolower($s['name']), mb_strtolower($name)));
        $routing += ($expect['plan_unchanged'] ?? false) ? ['plan_unchanged' => $plansAfter === $plansBefore] : [];
        $routing += isset($expect['proposals']) ? ['proposals' => count(array_filter($answer['blocks'] ?? [], fn (array $b): bool => ($b['type'] ?? null) === 'proposal')) === (int) $expect['proposals']] : [];
        $routing += isset($expect['plan_has']) ? ['plan_has' => collect((array) $expect['plan_has'])->every($named)] : [];
        $routing += isset($expect['plan_lacks']) ? ['plan_lacks' => ! collect((array) $expect['plan_lacks'])->contains($named)] : [];
        $own = count(array_filter($stopsAfter, fn (array $s): bool => ! $s['own']));
        $routing += isset($expect['plan_stops']) ? ['plan_stops' => is_array($expect['plan_stops']) ? $own >= $expect['plan_stops'][0] && $own <= $expect['plan_stops'][1] : $own === (int) $expect['plan_stops']] : [];
        $routing += isset($expect['not_before']) ? ['not_before' => collect((array) $expect['not_before'])->every(fn (string $time, string $name): bool => collect($stopsAfter)
            ->every(fn (array $s): bool => ! str_contains(mb_strtolower($s['name']), mb_strtolower($name)) || $s['start'] >= $time))] : [];
        // What a swap put in ("replace Rheingarten with a museum"): a stop that wasn't there before, of that kind.
        $added = array_filter($stopsAfter, fn (array $s): bool => ! in_array($s['name'], array_column(array_merge([], ...array_values($plansBefore)), 'name'), true));
        $routing += isset($expect['new_stop_kind']) ? ['new_stop_kind' => collect($added)->contains(fn (array $s): bool => in_array($s['category'], SpotCategory::finesForSelector((string) $expect['new_stop_kind']), true))] : [];
        $routing += ($expect['no_step_free'] ?? false) ? ['no_step_free' => preg_match('/step-?free|wheelchair/iu', (string) ($answer['text'] ?? '')) !== 1] : [];
        // Every card drawn as Around here's row (a park found for its pitch included).
        $routing += ($expect['all_rows'] ?? false) ? ['all_rows' => $shownPlaces !== [] && collect($shownPlaces)->every(fn (array $p): bool => is_array($p['row'] ?? null))] : [];
        // Its first sentence names a place it shows (a retry restates, never only offers something else).
        $first = (string) (preg_split('/(?<=[.!?])\s+/u', trim((string) ($answer['lead'] ?? $answer['text'] ?? '')))[0] ?? '');
        $routing += ($expect['first_names_place'] ?? false) ? ['first_names_place' => collect($shownPlaces)->contains(fn (array $p): bool => collect([$p['name'],
            trim((string) preg_split('/[,(]/u', (string) $p['name'])[0]), (string) ($p['area'] ?? '')])->filter(fn (string $n): bool => mb_strlen($n) > 3)
            ->contains(fn (string $n): bool => str_contains(mb_strtolower($first), mb_strtolower($n))))] : [];
        // Places that must not be suggested (a café that says no laptops, for work).
        // Places it shows that list a fact, as their own facts say ({"Wi-Fi": 2}: at least two list Wi-Fi).
        $routing += isset($expect['places_list']) ? ['places_list' => collect((array) $expect['places_list'])->every(fn (int $least, string $fact): bool => app(PlaceFacts::class)
            ->resolveMany(Spot::query()->whereIn('id', array_column($shownPlaces, 'id'))->get())
            ->filter(fn (array $facts): bool => in_array($fact, PlaceAmenities::listed($facts['practical'] ?? []), true))->count() >= $least)] : [];
        $routing += isset($expect['places_not']) ? ['places_not' => array_intersect((array) $expect['places_not'], array_column($shownPlaces, 'name')) === []] : [];
        $routing += isset($expect['not_says']) ? ['not_says' => preg_match('/'.$expect['not_says'].'/iu', (string) ($answer['text'] ?? '')) !== 1] : [];
        // A change asked for: the tool it called, the card it showed (done or proposed), or the place
        // that does what Composer never does; and none when it shouldn't change anything.
        $called = fn (string $tool): bool => collect($assistant->timings)->contains(fn (array $t): bool => str_starts_with($t[0], $tool.' '));
        // Proposed or made: the proposal or done card (session 2's blocks). A card asking which answer is theirs changes nothing.
        $asking = collect($answer['blocks'] ?? [])->filter(fn (array $b): bool => ($b['type'] ?? null) === 'proposal' && ($b['edit']['kind'] ?? null) === 'choice');
        $changed = collect($answer['blocks'] ?? [])->whereIn('type', ['proposal', 'done'])->reject(fn (array $b): bool => ($b['edit']['kind'] ?? null) === 'choice')
            ->map(fn (array $b): array => [...$b, 'state' => $b['type'] === 'done' ? 'done' : 'proposed']);
        // Asked which, with exactly these options; or proposed as this answer, in the card's words.
        $routing += isset($expect['choices']) ? ['choices' => $asking->contains(fn (array $b): bool => collect($b['edit']['options'])->pluck('value')->sort()->values()->all()
            === collect($expect['choices'])->sort()->values()->all())] : [];
        $routing += isset($expect['proposed_to']) ? ['proposed_to' => $changed->contains(fn (array $b): bool => ($b['to'] ?? null) === $expect['proposed_to'])] : [];
        $routing += isset($expect['acted']) ? ['acted' => $called($expect['acted'])] : [];
        $routing += isset($expect['changed']) ? ['changed' => $changed->contains('state', $expect['changed'])] : [];
        // Every place handed over that's expected (one, or both ways of stopping).
        $routing += isset($expect['handoff']) ? ['handoff' => array_diff((array) $expect['handoff'], array_column(array_filter($answer['blocks'] ?? [], fn (array $b): bool => ($b['type'] ?? null) === 'handoff'), 'what')) === []] : [];
        $routing += ($expect['no_change'] ?? false) ? ['no_change' => $changed->isEmpty()] : [];
        // What the answer must say (a fact that isn't listed, why nothing is open), as a pattern over its words.
        $routing += isset($expect['says']) ? ['says' => preg_match('/'.$expect['says'].'/iu', (string) ($answer['text'] ?? '')) === 1] : [];
        $planExpect = array_diff_key($expect, self::ANSWER_EXPECT);
        $gates = array_fill_keys([...PlanChecks::GATES, ...self::ANSWER_GATES], []);
        $gates = [...$gates, ...$this->said($user, $answer, $assistant, $now, $earlier, $request['text'], $plan !== null)];
        if ($plan === null) {
            // A plan was asked for in words ("plan my Saturday", "park first, then dinner") and none came.
            // Other requests may be answered with places or listings instead; then there's no plan to check.
            $planned = $planExpect !== [] && ! ($expect['open'] ?? false) && ! ($expect['allow_empty'] ?? false) && ! isset($expect['routed']) && preg_match(self::ASKS_TO_PLAN, $request['text']) === 1
                ? ['planned' => false] : [];

            return $row + ['expectations' => $routing + $planned, 'gates' => $gates];
        }

        $slots = $plan['slots'] ?? [];
        $constraints = $plan['constraints'] ?? null;
        $row['slots'] = array_map(fn (array $s): array => [
            'id' => $s['id'], 'name' => $s['name'], 'category' => $s['category'], 'veedel' => $s['veedel'] ?? null,
            'start' => $s['start_time'] ?? null, 'end' => $s['end_time'] ?? null, 'travel' => $s['travel_min_from_previous'] ?? null,
            'outdoor' => $s['outdoor'] ?? null, 'cost' => $s['cost_tier'] ?? null,
        ], $slots);
        $row['constraints'] = $constraints;
        $gated = $constraints === null ? ['gates' => []] : $checks->gates($slots, CarbonImmutable::parse($constraints['window_start']), CarbonImmutable::parse($constraints['window_end']));

        return $row + [
            'gates' => $gated['gates'] + $gates,
            'expectations' => $routing + ($planExpect === [] ? [] : $checks->expectations($planExpect, $slots, $constraints)),
        ];
    }

    /**
     * The answer's gates: a dead end (nothing said and nothing shown), a sentence what it
     * looked at doesn't hold (the live check, again), a "because" the person's taste doesn't
     * back, and, after they turned suggestions down, any of them shown again.
     *
     * @param  array<string, mixed>  $answer  the answer message
     * @param  array{event: array<int, string>, spot: array<int, string>, areas: list<string>}  $earlier  what the conversation showed before
     * @return array<string, list<string>>
     */
    private function said(User $user, array $answer, Assistant $assistant, CarbonImmutable $now, array $earlier, string $text, bool $planned): array
    {
        $dead = $answer === [] || (trim((string) ($answer['text'] ?? '')) === '' && ($answer['blocks'] ?? []) === [] && ! $planned);
        // As the live check reads it: also what this conversation showed before ("the way to Café Nova").
        $read = ['now' => $now->setTimezone('Europe/Berlin')->format('l j F, H:i').', Cologne', 'home' => 'Home Veedel: '.$user->veedel,
            'earlier' => implode("\n", [...array_values($earlier['event']), ...array_values($earlier['spot'])]), ...$assistant->read];
        $invented = [];
        // Each sentence it kept, against everything it read (not only what it cited): a sentence may
        // speak of the person from their own facts, and count the listings, places and choices it cites.
        foreach (($answer['written'] ?? null) === 'ai' ? $assistant->kept : [] as $sentence) {
            $count = max(count(preg_grep('/^(e\d+@\d+|spot:\d+)$/', $sentence['uses'])), count(preg_grep('/^(e\d+@\d+|spot:\d+)$/', array_keys($assistant->read))))
                + count(collect($answer['blocks'] ?? [])->firstWhere('type', 'choices')['options'] ?? [])
                // ...and the stops of a plan it made or this conversation made ("two museums").
                + array_sum(array_map(fn (string $id): int => substr_count($assistant->read[$id], "\n- "), preg_grep('/^plan\d*$/', array_keys($assistant->read))));
            if (! app(AroundCheck::class)->holds($sentence['text'], implode("\n", $read), preg_grep(AssistantTools::PERSONAL_IDS, array_keys($assistant->read)) !== [], $count)) {
                $invented[] = $sentence['text'];
            }
        }
        $taste = implode("\n", array_intersect_key($assistant->read, array_flip(preg_grep('/^(t|a|h|w)\d+$/', array_keys($assistant->read)))));
        $unbacked = array_values(array_filter([...array_column($answer['events'] ?? [], 'because'), ...array_column($answer['places'] ?? [], 'because')],
            fn (string $because): bool => $taste === '' || ! app(AroundCheck::class)->holds($because, $taste, true)));
        $repeated = preg_match(self::REFUSAL, $text) === 1 ? [
            ...array_values(array_intersect_key($earlier['event'], array_flip(array_column($answer['events'] ?? [], 'id')))),
            ...array_values(array_intersect_key($earlier['spot'], array_flip(array_column($answer['places'] ?? [], 'id')))),
        ] : [];

        return ['dead_end' => $dead ? [Str::limit((string) ($answer['text'] ?? ''), 120)] : [], 'invented' => $invented,
            'because_unbacked' => $unbacked, 'repeated_after_refusal' => $repeated];
    }

    /**
     * One message, as the page sends it: into the conversation, then Composer's answer
     * (Ask), from the persona's own starting point, under a say-so for this request.
     *
     * @param  array{lat?: float, lng?: float}|null  $origin
     * @return array{0: Assistant, 1: list<array<string, mixed>>} the assistant that answered, and the messages its answer added
     */
    private function message(User $user, string $text, ?array $origin, bool $paperwork = false): array
    {
        $store = app(WorkspaceStore::class);
        $store->command($user, ['action' => 'message', 'text' => $text, 'revision' => $store->read($user)['revision']]);
        $before = count(collect($store->read($user)['conversations'])->first()['messages'] ?? []);
        $http = Request::create('/composer/ask', 'POST', isset($origin['lat'], $origin['lng']) ? ['lat' => $origin['lat'], 'lng' => $origin['lng'], 'from_label' => 'Home'] : []);
        // Binding the request hands its user resolver to the auth guard, and its From to the tools.
        app()->instance('request', $http);
        Auth::setUser($user);
        app()->instance(Assistant::class, $assistant = app()->make(Assistant::class));
        try {
            $workspace = app(Ask::class)->answer($user, $this->acceptance(ProcessingPurpose::ComposerAssist), 'Composer', $http,
                $paperwork ? $this->acceptance(ProcessingPurpose::ComposerPaperwork) : null);
        } finally {
            app()->forgetInstance(Assistant::class);
        }

        return [$assistant, array_slice(collect($workspace['conversations'])->first()['messages'] ?? [], $before)];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function summarise(array $results): array
    {
        $gates = array_fill_keys([...PlanChecks::GATES, ...self::ANSWER_GATES], 0);
        $expect = [];
        $routed = [];
        $written = [];
        $blocks = [];
        $asking = array_values(array_filter(array_column($results, 'ask_ms')));
        sort($asking);
        $tokens = ['input' => 0, 'output' => 0, 'calls' => 0];
        foreach ($results as $r) {
            foreach (['input', 'output', 'calls'] as $key) {
                $tokens[$key] += $r['tokens'][$key] ?? 0;
            }
            $written[$r['said']['without_ai'] ?? $r['said']['written'] ?? 'none'] = ($written[$r['said']['without_ai'] ?? $r['said']['written'] ?? 'none'] ?? 0) + 1;
            foreach ($r['said']['blocks'] ?? [] as $block) {
                $blocks[$block] = ($blocks[$block] ?? 0) + 1;
            }
            foreach ($r['gates'] ?? [] as $gate => $hits) {
                $gates[$gate] += count($hits);
            }
            foreach ($r['expectations'] ?? [] as $key => $ok) {
                $expect[$key] ??= ['pass' => 0, 'total' => 0];
                $expect[$key]['total']++;
                $expect[$key]['pass'] += $ok ? 1 : 0;
            }
            $routed[$r['routed'] ?? 'error'] = ($routed[$r['routed'] ?? 'error'] ?? 0) + 1;
        }
        $cost = app(LlmCalls::class)->cost((string) config('services.llm.model'), $tokens['input'], $tokens['output']);
        $pick = fn (float $q): ?int => $asking === [] ? null : $asking[(int) floor($q * (count($asking) - 1))];
        $all = count($results);
        $passedAll = count(array_filter($results, fn (array $r): bool => ! isset($r['error'])
            && array_sum(array_map('count', $r['gates'] ?? [])) === 0
            && ! in_array(false, $r['expectations'] ?? [], true)));

        return [
            'requests' => $all,
            'errors' => count(array_filter($results, fn (array $r): bool => isset($r['error']))),
            'gates' => $gates,
            'gates_total' => array_sum($gates),
            'requests_fully_passing' => $passedAll,
            'expectations' => $expect,
            'routed' => $routed,
            // Who wrote the answer: the AI, or Composer without it (and why).
            'written' => $written,
            'blocks' => $blocks,
            // The whole answer, as the page waits for it: lookups, answer, a plan if any.
            'ask_ms' => ['p50' => $pick(0.5), 'p95' => $pick(0.95), 'over_6s' => count(array_filter($asking, fn (int $ms): bool => $ms > 6000))],
            'effort' => (string) config('services.llm.effort'),
            'tokens' => $tokens + ['cost_usd' => $cost, 'cost_per_request_usd' => $cost === null || $all === 0 ? null : round($cost / $all, 6)],
        ];
    }

    private function report(array $summary, array $results): void
    {
        $this->info(sprintf('%d requests · %d fully passing · %d errors · gates %d (%s)',
            $summary['requests'], $summary['requests_fully_passing'], $summary['errors'], $summary['gates_total'],
            collect($summary['gates'])->map(fn (int $n, string $g): string => "{$g} {$n}")->implode(', ')));
        $this->line('Written: '.json_encode($summary['written']).' · blocks '.json_encode($summary['blocks']).' · routed '.json_encode($summary['routed']));
        $this->line('Effort '.$summary['effort'].' · answer p50 '.$summary['ask_ms']['p50'].' ms, p95 '.$summary['ask_ms']['p95'].' ms, over 6 s '.$summary['ask_ms']['over_6s']
            .' · tokens '.json_encode($summary['tokens']));
        $this->table(['expectation', 'pass', 'of', 'rate'], collect($summary['expectations'])
            ->map(fn (array $e, string $k): array => [$k, $e['pass'], $e['total'], round(100 * $e['pass'] / max(1, $e['total'])).'%'])
            ->sortBy(fn (array $row): int => (int) $row[3])->values()->all());

        foreach ($results as $r) {
            foreach ($r['gates'] ?? [] as $gate => $hits) {
                foreach ($hits as $hit) {
                    $this->warn("GATE {$gate} · {$r['id']} · {$hit}");
                }
            }
            if (isset($r['error'])) {
                $this->warn("ERROR {$r['id']} · {$r['error']}");
            }
        }
    }

    private function user(array $request): User
    {
        $user = User::query()->firstOrNew(['email' => "composer-eval+{$request['persona']}{$this->option('cohort')}@example.invalid"]);
        $user->forceFill([
            'name' => 'Composer eval · '.$request['persona'],
            'password' => $user->password ?? Hash::make(Str::random(40)),
            'email_verified_at' => $user->email_verified_at ?? now(),
            'veedel' => $request['home_veedel'],
            'situation' => self::SITUATIONS[$request['persona']] ?? Situation::Other,
            // A persona's step-free need is in its settings, as a person's is.
            'needs_step_free' => in_array('step_free', $request['needs'] ?? [], true),
            'transport_mode' => null, 'has_deutschlandticket' => false, 'german_level' => null, 'city' => null,
        ])->save();
        // What a case changed before stays out of the next: saved places, Home and Work.
        SpotFeedback::query()->where('user_id', $user->id)->delete();
        EventBookmark::query()->where('user_id', $user->id)->delete();
        EventReminder::query()->where('user_id', $user->id)->delete();
        $user->places()->delete();
        // A case's own places (Work, a Gym), with their points and times.
        foreach ($request['my_places'] ?? [] as $i => $place) {
            $user->places()->create([...$place, 'emoji' => '📍', 'sort_order' => $i]);
        }
        // ...and Travel's stars and planned ways, their alerts, mutes and notification kinds.
        FavouriteStop::query()->where('user_id', $user->id)->delete();
        PlannedTrip::query()->where('user_id', $user->id)->delete();
        $user->notificationPreference()->delete();
        foreach (app(MuteService::class)->activeMutes($user) as $mute) {
            app(MuteService::class)->unmute($user, $mute['type'], $mute['key']);
        }
        Alert::query()->where('user_id', $user->id)->delete();
        // A remembered Allow for their Paperwork: each case starts without one.
        ProcessingOptIn::query()->where('user_id', $user->id)->delete();
        for ($n = 1; $n <= (int) ($request['alerts'] ?? 0); $n++) {
            Alert::record($user->id, "eval:{$n}", ['type' => AlertType::System, 'subtype' => 'transit_delay', 'severity' => 'info',
                'category' => 'transit', 'lane' => 'action', 'title' => "Synthetic alert {$n}", 'body' => 'For composer:eval only.']);
        }
        // A persona with a Paperwork record: its answers, as the case sets them.
        if (is_array($request['paperwork'] ?? null)) {
            $case = app(EnsureAccountHolder::class)->dossier($user);
            $view = app(ConfirmedFactView::class)->forCase($case, now()->toDateString());
            foreach ($request['paperwork'] as $key => $value) {
                if (($view['values'][$key] ?? null) !== $value) {
                    app(RecordFactChange::class)->execute($user, $case->person, $key, $value, null, $case->fresh()->fact_version);
                }
            }
        }
        // A persona with personalisation on, and a taste to go with it.
        $personalised = (bool) ($request['personalised'] ?? false);
        $user->userSetting()->updateOrCreate([], ['settings' => ['personalisation' => $personalised]]);
        $subject = Pseudonym::for($user);
        DB::table('user_affinities')->where('subject_key', $subject)->delete();
        if ($personalised) {
            DB::table('user_affinities')->insert(array_map(fn (array $row): array => ['subject_key' => $subject, 'dimension' => $row[0], 'key' => $row[1],
                'context' => 'all', 'score' => $row[2], 'signals_count' => $row[3], 'updated_at' => now()], self::TASTE));
        }

        return $user->fresh();
    }

    /**
     * @return array{consent: true, request_id: string, notice_version: mixed, provider_version: string}
     */
    private function acceptance(ProcessingPurpose $purpose): array
    {
        return [
            'consent' => true,
            'request_id' => (string) Str::uuid(),
            'notice_version' => config('bureaucracy_privacy.notice_version'),
            'provider_version' => $purpose->providerVersion(),
        ];
    }

    /** The scenario's weather instead of the live forecast. */
    private function fixWeather(string $weather): void
    {
        $rain = $weather === 'rain';
        app()->instance(WeatherService::class, new class($rain) extends WeatherService
        {
            public function __construct(private bool $rain) {}

            public function getCurrentWeather(float $lat = 50.9375, float $lng = 6.9603): array
            {
                return ['available' => true, 'temperature' => 12, 'precipitation' => $this->rain ? 1.2 : 0, 'icon' => $this->rain ? 'rain' : 'clear', 'label' => $this->rain ? 'Rain' : 'Clear',
                    'condition' => $this->rain ? 'Rain' : 'Clear sky'];
            }

            public function getForecast(float $lat = 50.9375, float $lng = 6.9603): array
            {
                return ['available' => true, 'rain_soon' => $this->rain, 'rain_starts' => $this->rain ? 'now' : null, 'rain_summary' => $this->rain ? 'Rain for the next few hours' : 'Dry for the next few hours'];
            }
        });
    }

    /**
     * Their plans' stops, by date: name, start, end, and whether it's one of their own.
     *
     * @param  array<string, mixed>  $plans
     * @return array<string, list<array{name: string, start: string, end: ?string, own: bool, category: ?string}>>
     */
    private static function stops(array $plans): array
    {
        return collect($plans)->map(fn (mixed $plan): array => collect(is_array($plan) ? ($plan['slots'] ?? []) : [])->map(fn (array $slot): array => [
            'name' => (string) ($slot['name'] ?? ''), 'start' => (string) ($slot['start_time'] ?? ''), 'end' => $slot['end_time'] ?? null, 'category' => $slot['category'] ?? null,
            'own' => ($slot['is_appointment'] ?? false) || in_array($slot['type'] ?? null, ['personal', 'appointment'], true)])->sortBy('start')->values()->all())->sortKeys()->all();
    }

    /**
     * A plan the case starts from, put in as Composer puts in a plan it made in a conversation. Its
     * stops are named, since spot ids change with each catalogue import: the active canonical place
     * of that name, the oldest if there are two.
     *
     * @param  array{day: string, stops: list<array{place: string, start: string, end: string}>}  $seed
     */
    private function seedPlan(User $user, array $seed, CarbonImmutable $now): void
    {
        $day = PlanDay::dayOf($seed['day'] ?? null, $now) ?? throw new \RuntimeException('Unknown seed day.');
        $ids = array_map(fn (array $stop): string => 'spot:'.(Spot::query()->canonical()->where('is_active', true)->where('name', $stop['place'])->orderBy('id')->value('id')
            ?? throw new \RuntimeException("Seed place {$stop['place']} isn't in the catalogue.")), $seed['stops']);
        $candidates = collect(app(CandidateRepository::class)->byIds($ids, $day))->keyBy('id');
        $slots = array_map(fn (array $stop, string $id): array => (new PlanSlot($candidates[$id] ?? throw new \RuntimeException("Seed place {$stop['place']} is unavailable."),
            $day->setTimeFromTimeString($stop['start']), $day->setTimeFromTimeString($stop['end']), 0))->toArray(), $seed['stops'], $ids);
        $token = 'eval-'.Str::random(12);
        app(PrivatePlanCache::class)->putHandoff($user, $token, ['date' => $day->toDateString(),
            'constraints' => (new Constraints(windowStart: $day->setTime(10, 0), windowEnd: $day->setTime(20, 0)))->toArray(), 'slots' => $slots]);
        $workspace = app(WorkspaceStore::class);
        $workspace->answer($user, (string) ($workspace->read($user)['active'] ?? 'initial'), ['text' => 'Here is a plan for '.$day->isoFormat('dddd').'.', 'extra' => []], $token);
    }
}
