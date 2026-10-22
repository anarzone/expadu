<?php

namespace App\Console\Commands;

use App\Ai\LlmCalls;
use App\Composer\Answers\Answers;
use App\Composer\Answers\AroundCheck;
use App\Composer\Answers\AroundNow;
use App\Composer\Assistant\Assistant;
use App\Composer\CandidateRepository;
use App\Composer\Contracts\ParsesPrompt;
use App\Composer\Eval\PlanChecks;
use App\Composer\HeuristicPromptParser;
use App\Composer\ParsedPrompt;
use App\Composer\PromptIntent;
use App\Composer\WorkspaceStore;
use App\Enums\Situation;
use App\Http\Controllers\ComposerController;
use App\Models\User;
use App\Personalisation\Pseudonym;
use App\Privacy\ProcessingConsentStore;
use App\Privacy\ProcessingPermit;
use App\Privacy\ProcessingPurpose;
use App\Profile\Profile;
use App\Profile\ProfileEngine;
use App\Services\WeatherService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The Composer evaluation: runs a fixed set of requests (tests/Fixtures/
 * composer-eval/requests.json) through the real parse and compose paths for
 * one synthetic user per persona, and checks every plan against the database
 * (PlanChecks). Each request runs at its weekday and time in the coming week,
 * so events are real, with the scenario's weather. Gates must stay at zero:
 * the plan gates (PlanChecks) and, for what Composer says instead of planning,
 * no dead end and nothing the AI's lead says that its listings don't.
 *
 * Writes only to the synthetic users (composer-eval+…@example.invalid): their
 * composer runs, active plan and hand-off, as a person composing would.
 */
class EvaluateComposer extends Command
{
    protected $signature = 'composer:eval
        {--set=tests/Fixtures/composer-eval/requests.json : Request set}
        {--only= : Comma-separated request ids, persona names or scenario names}
        {--limit= : Run at most this many requests}
        {--parser=configured : configured (the app binding) or heuristic}
        {--week= : Monday of the week to run in (default: next Monday)}
        {--label=baseline : Name for the report file}
        {--effort= : The parser\'s effort for this run (low, medium, high)}
        {--cohort= : Suffix for the synthetic users, so runs can go side by side}
        {--parse-only : Read and answer each request, but compose no plan}';

    protected $description = 'Evaluate Composer plans against a fixed request set';

    /** What Composer says instead of a plan: never a dead end, never a fact the listings don't hold. */
    private const ANSWER_GATES = ['dead_end', 'invented', 'because_unbacked'];

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
        config(['bureaucracy_privacy.daily_limits.'.ProcessingPurpose::ComposerParse->value => 100000,
            'bureaucracy_privacy.daily_limits.'.ProcessingPurpose::ComposerAssist->value => 100000]);
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
            'parser' => $this->option('parser'),
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
        $profile = app(ProfileEngine::class)->build($user);
        $parser = $this->option('parser') === 'heuristic' ? app(HeuristicPromptParser::class) : app(ParsesPrompt::class);
        $row = ['id' => $request['id'], 'text' => $request['text'], 'now' => $now->toIso8601String(), 'weather' => $request['weather']];

        $started = hrtime(true);
        $around = null;
        try {
            // As ComposerController::parse() does it.
            $routed = app(Answers::class)->route($user, $request['text'], $now, app(WorkspaceStore::class)->read($user)['focus'] ?? null);
            $ai = method_exists($parser, 'processingContext');
            $around = $ai && AroundNow::opens($routed) && ! ProcessingPurpose::ComposerAssist->available() ? app(AroundNow::class)->listings($now) : null;
            $facts = $around !== null ? AroundNow::prompt($around) : null;
            $permit = $ai
                ? app(ProcessingConsentStore::class)->grant($user, ProcessingPurpose::ComposerParse,
                    $parser::processingContext($request['text'], $profile, $facts), $this->acceptance(ProcessingPurpose::ComposerParse))
                : null;
            $parsed = $permit !== null && $routed['kind'] === 'around' && ProcessingPurpose::ComposerAssist->available()
                ? new ParsedPrompt(PromptIntent::Open, source: 'llm')
                : ($ai ? $parser->parse($request['text'], $profile, $now, $permit, $facts) : $parser->parse($request['text'], $profile, $now, $permit));
            if ($around !== null) {
                app(AroundNow::class)->keep($user, $request['text'], $around, $parsed->reply);
            }
        } catch (Throwable $e) {
            return $row + ['error' => 'parse: '.class_basename($e).': '.Str::limit($e->getMessage(), 200)];
        }
        $row['parse_ms'] = (int) ((hrtime(true) - $started) / 1e6);
        $row['tokens'] = $parser->usage ?? null;
        $row['parse'] = ['intent' => $parsed->intent->value, 'source' => $parsed->source, 'kind' => $routed['kind'], 'constraints' => $parsed->plan?->toArray()];
        // The same rules ComposerController::parse() applies.
        if ($parsed->intent === PromptIntent::TakeMeThere && filled($parsed->query) && in_array($routed['kind'], Answers::UNSPECIFIC, true) && ! isset($routed['place'])) {
            Answers::tripHint($user, $request['text'], (string) $parsed->query);
            $routed = app(Answers::class)->route($user, $request['text'], $now, app(WorkspaceStore::class)->read($user)['focus'] ?? null);
        }
        $assistStarted = hrtime(true);
        $assisted = $this->assisted($user, $request['text'], $profile, $parsed, $permit ?? null, $now);
        $row['assist_ms'] = $assisted !== null ? (int) ((hrtime(true) - $assistStarted) / 1e6) : null;
        $answered = Answers::handles($routed, $parsed->intent) || $assisted !== null;
        $row['routed'] = $answered ? 'answer' : ($parsed->intent === PromptIntent::PlanDay && $parsed->plan !== null ? 'compose' : 'reply:'.$parsed->intent->value);

        // Where a request should go ("What's on tonight?" → an answer), checked apart from any plan.
        // Parse-only, a request with no route named is a plan to compose (the set's own kind).
        $route = $request['expect']['routed'] ?? ($this->option('parse-only') ? 'compose' : null);
        $routing = $route !== null ? ['routed' => $row['routed'] === $route] : [];
        $planExpect = array_diff_key($request['expect'], ['routed' => true, 'around' => true, 'answer_kind' => true]);
        $gates = array_fill_keys([...PlanChecks::GATES, ...self::ANSWER_GATES], []);
        if ($row['routed'] === 'compose' && $this->option('parse-only')) {
            return $row + ['expectations' => $routing, 'gates' => $gates];
        }
        if ($row['routed'] !== 'compose') {
            try {
                [$said, $gated] = $this->said($user, $request['text'], $routed, $parsed, $answered, $around, $now, $assisted);
            } catch (Throwable $e) {
                return $row + ['error' => 'answer: '.class_basename($e).': '.Str::limit($e->getMessage(), 200)];
            }
            $routing += isset($request['expect']['around']) ? ['around' => ($said['kind'] ?? null) === 'around'] : [];
            $routing += isset($request['expect']['answer_kind']) ? ['answer_kind' => ($said['kind'] ?? null) === $request['expect']['answer_kind']] : [];

            return $row + ['said' => $said, 'expectations' => $routing + ($routing === [] ? $checks->expectations($planExpect, [], null) : []), 'gates' => $gated + $gates];
        }

        $composeStarted = hrtime(true);
        try {
            $http = Request::create('/composer/compose', 'POST', [
                'constraints' => $parsed->plan->toArray(),
                'lat' => $request['origin']['lat'],
                'lng' => $request['origin']['lng'],
                'from_label' => 'Home',
            ]);
            // Binding the request hands its user resolver to the auth guard.
            app()->instance('request', $http);
            Auth::setUser($user);
            /** @var JsonResponse $response */
            $response = app()->call([app(ComposerController::class), 'compose'], ['request' => $http]);
            $body = $response->getData(true);
        } catch (ValidationException $e) {
            return $row + ['error' => 'compose rejected: '.json_encode($e->errors())];
        } catch (Throwable $e) {
            return $row + ['error' => 'compose: '.class_basename($e).': '.Str::limit($e->getMessage(), 200)];
        }
        $row['compose_ms'] = (int) ((hrtime(true) - $composeStarted) / 1e6);
        if ($response->getStatusCode() !== 200) {
            return $row + ['error' => 'compose '.$response->getStatusCode().': '.json_encode($body)];
        }

        $slots = $body['plan']['slots'] ?? [];
        $constraints = $parsed->plan->toArray();
        $gated = $checks->gates($slots, CarbonImmutable::parse($constraints['window_start']), CarbonImmutable::parse($constraints['window_end']));

        return $row + [
            'slots' => array_map(fn (array $s): array => [
                'id' => $s['id'], 'name' => $s['name'], 'category' => $s['category'], 'veedel' => $s['veedel'] ?? null,
                'start' => $s['start_time'] ?? null, 'end' => $s['end_time'] ?? null, 'travel' => $s['travel_min_from_previous'] ?? null,
                'outdoor' => $s['outdoor'] ?? null, 'cost' => $s['cost_tier'] ?? null,
            ], $slots),
            'notices' => array_column($body['notices'] ?? [], 'kind'),
            'gates' => $gated['gates'] + $gates,
            'expectations' => $routing + $checks->expectations($planExpect, $slots, $constraints),
        ];
    }

    /**
     * What the person is told instead of a plan, as the workspace says it (WorkspaceStore's
     * `answer` and `reply`), and its gates: a dead end (no reply of its own, the old
     * "isn't something Composer knows", or nothing to do next) and, in the AI's lead,
     * any sentence its listings don't hold. The page's own replies (search, paperwork,
     * journey) are named, not judged.
     *
     * @param  array<string, mixed>  $routed
     * @param  array<string, mixed>|null  $around
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>}
     */
    private function said(User $user, string $text, array $routed, ParsedPrompt $parsed, bool $answered, ?array $around, CarbonImmutable $now, ?array $assisted = null): array
    {
        $answers = app(Answers::class);
        $page = match ($parsed->intent) {
            PromptIntent::BureaucracyQ => 'bureaucracy',
            PromptIntent::TakeMeThere => 'journey',
            PromptIntent::Find => 'find',
            default => null,
        };
        $reply = match (true) {
            $answered => $answers->answer($user, $text, $routed, [], $now)
                ?? (in_array($routed['kind'], ['checklist', 'bureaucracy', 'journey', 'onway'], true) ? false : $answers->around($user, $text, $routed, [], $now)),
            $page !== null => false,
            default => $answers->around($user, $text, ['kind' => 'clarify'] + $routed, [], $now),
        };
        if ($reply === false) {
            return [['kind' => 'page:'.($page ?? $routed['kind'])], []];
        }

        $said = ['kind' => $reply['extra']['kind'] ?? null, 'text' => $reply['text'], 'written' => $reply['extra']['written'] ?? null,
            'events' => count($reply['extra']['events'] ?? []), 'facts' => array_column($reply['extra']['facts'] ?? [], 'label'),
            'next' => array_column($reply['extra']['suggestions'] ?? [], 'label')];
        $dead = $reply['text'] === '' || str_contains($reply['text'], 'something Composer knows')
            || (($said['kind'] === 'around') && $said['next'] === []);
        $invented = [];
        $unbacked = [];
        if ($assisted !== null) {
            $said['assistant'] = ['events' => count($assisted['events']), 'places' => array_column($assisted['places'], 'name'), 'looked' => array_keys($assisted['read']),
                'because' => array_values(array_filter([...array_column($assisted['events'], 'because'), ...array_column($assisted['places'], 'because')])),
                'taste' => $assisted['taste'], 'invite' => $assisted['invite']];
            // Every "because" holds what the person's taste says (the answer's own check, again).
            $taste = implode("\n", array_intersect_key($assisted['read'], array_flip(preg_grep('/^(t|a|h|w|d)\d+$/', array_keys($assisted['read'])))));
            foreach ($said['assistant']['because'] as $because) {
                if ($taste === '' || ! app(AroundCheck::class)->holds($because, $taste."\n".implode("\n", $assisted['read']), true)) {
                    $unbacked[] = $because;
                }
            }
            // As the live check reads it: the time and the weather, then everything it looked at.
            $around = ['items' => ['now' => $now->setTimezone('Europe/Berlin')->format('l j F, H:i').', Cologne', ...$assisted['read']]];
        } elseif ($around !== null) {
            $said['ai_sentences'] = count($parsed->reply);
            $said['ai_dropped'] = array_values(array_map(fn (array $sentence): string => $sentence['text'].' ['.implode(',', $sentence['uses']).']',
                array_filter($parsed->reply, fn (array $sentence): bool => app(AroundNow::class)->checked($around['items'], [$sentence]) === '')));
            $said['ai_kept'] = $said['ai_sentences'] - count($said['ai_dropped']);
        }
        if ($said['written'] === 'ai' && $around !== null) {
            $listed = implode("\n", $around['items']);
            foreach (preg_split('/(?<=[.!?])\s+/u', $reply['text']) as $sentence) {
                if (! app(AroundCheck::class)->holds($sentence, $listed)) {
                    $invented[] = $sentence;
                }
            }
        }

        return [$said, ['dead_end' => $dead ? [Str::limit($reply['text'], 120)] : [], 'invented' => $invented, 'because_unbacked' => $unbacked]];
    }

    /**
     * As ComposerController::assisted(): the assistant's answer to an open or vague request,
     * kept for the answer that follows.
     *
     * @return array<string, mixed>|null
     */
    private function assisted(User $user, string $text, Profile $profile, ParsedPrompt $parsed, ?ProcessingPermit $permit, CarbonImmutable $now): ?array
    {
        if ($permit === null || ! ProcessingPurpose::ComposerAssist->available() || ! Assistant::wanted($parsed, $text)) {
            return null;
        }
        $answer = app(Assistant::class)->answer($user, $text, $profile, $now, [CandidateRepository::COLOGNE_LAT, CandidateRepository::COLOGNE_LNG],
            app(ProcessingConsentStore::class)->derive($permit, ProcessingPurpose::ComposerAssist, Assistant::processingContext($text, $profile)),
            app(WorkspaceStore::class)->read($user)['plans'] ?? []);
        if ($answer !== null) {
            app(AroundNow::class)->keepAssisted($user, $text, $answer, $now);
        }

        return $answer;
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
        $sources = [];
        $latency = [];
        $leads = ['ai' => 0, 'listings' => 0, 'ai_sentences' => 0, 'ai_kept' => 0, 'because' => 0, 'taste' => 0, 'invite' => 0];
        $answering = [];
        $assisting = array_values(array_filter(array_column($results, 'assist_ms')));
        sort($assisting);
        $parsing = [];
        $tokens = ['input' => 0, 'output' => 0];
        foreach ($results as $r) {
            if (isset($r['parse_ms'])) {
                $parsing[] = $r['parse_ms'];
            }
            $tokens['input'] += $r['tokens']['input'] ?? 0;
            $tokens['output'] += $r['tokens']['output'] ?? 0;
            if (isset($r['said']['written'])) {
                $leads[$r['said']['written']]++;
                $leads['ai_sentences'] += $r['said']['ai_sentences'] ?? 0;
                $leads['ai_kept'] += $r['said']['ai_kept'] ?? 0;
                $leads['because'] += count($r['said']['assistant']['because'] ?? []);
                $leads['taste'] += ($r['said']['assistant']['taste'] ?? false) ? 1 : 0;
                $leads['invite'] += ($r['said']['assistant']['invite'] ?? false) ? 1 : 0;
                $answering[] = $r['parse_ms'];
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
            if (isset($r['parse']['source'])) {
                $sources[$r['parse']['source']] = ($sources[$r['parse']['source']] ?? 0) + 1;
            }
            if (isset($r['compose_ms'])) {
                $latency[] = $r['parse_ms'] + $r['compose_ms'];
            }
        }
        sort($latency);
        sort($answering);
        sort($parsing);
        $cost = app(LlmCalls::class)->cost((string) config('services.llm.model'), $tokens['input'], $tokens['output']);
        $pick = fn (float $q, ?array $of = null): ?int => ($of ?? $latency) === [] ? null : ($of ?? $latency)[(int) floor($q * (count($of ?? $latency) - 1))];
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
            'leads' => $leads,
            'parse_source' => $sources,
            'latency_ms' => ['p50' => $pick(0.5), 'p95' => $pick(0.95)],
            // The parse call that also writes the lead, for what Composer says instead of a plan.
            'answer_parse_ms' => ['p50' => $pick(0.5, $answering), 'p95' => $pick(0.95, $answering)],
            'parse_ms' => ['p50' => $pick(0.5, $parsing), 'p95' => $pick(0.95, $parsing)],
            // The assistant's lookups and answer, after the read (open requests only).
            'assist_ms' => ['n' => count($assisting), 'p50' => $pick(0.5, $assisting), 'p95' => $pick(0.95, $assisting)],
            'effort' => (string) config('services.llm.effort'),
            'tokens' => $tokens + ['cost_usd' => $cost, 'cost_per_request_usd' => $cost === null || $all === 0 ? null : round($cost / $all, 6)],
        ];
    }

    private function report(array $summary, array $results): void
    {
        $this->info(sprintf('%d requests · %d fully passing · %d errors · gates %d (%s)',
            $summary['requests'], $summary['requests_fully_passing'], $summary['errors'], $summary['gates_total'],
            collect($summary['gates'])->map(fn (int $n, string $g): string => "{$g} {$n}")->implode(', ')));
        $this->line('Leads: '.json_encode($summary['leads']).' · answer parse p50 '.$summary['answer_parse_ms']['p50'].' ms, p95 '.$summary['answer_parse_ms']['p95'].' ms');
        $this->line('Assistant: '.json_encode($summary['assist_ms']));
        $this->line('Effort '.$summary['effort'].' · parse p50 '.$summary['parse_ms']['p50'].' ms, p95 '.$summary['parse_ms']['p95'].' ms · tokens '.json_encode($summary['tokens']));
        $this->line('Routed: '.json_encode($summary['routed']).' · parser: '.json_encode($summary['parse_source'])
            .' · latency p50 '.$summary['latency_ms']['p50'].' ms, p95 '.$summary['latency_ms']['p95'].' ms');
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
        ])->save();
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
}
