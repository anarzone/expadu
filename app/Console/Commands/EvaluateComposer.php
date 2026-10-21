<?php

namespace App\Console\Commands;

use App\Composer\Answers\Answers;
use App\Composer\Contracts\ParsesPrompt;
use App\Composer\Eval\PlanChecks;
use App\Composer\HeuristicPromptParser;
use App\Composer\PromptIntent;
use App\Composer\WorkspaceStore;
use App\Enums\Situation;
use App\Http\Controllers\ComposerController;
use App\Models\User;
use App\Privacy\ProcessingConsentStore;
use App\Privacy\ProcessingPurpose;
use App\Profile\ProfileEngine;
use App\Services\WeatherService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The Composer evaluation: runs a fixed set of requests (tests/Fixtures/
 * composer-eval/requests.json) through the real parse and compose paths for
 * one synthetic user per persona, and checks every plan against the database
 * (PlanChecks). Each request runs at its weekday and time in the coming week,
 * so events are real, with the scenario's weather. Gates must stay at zero.
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
        {--label=baseline : Name for the report file}';

    protected $description = 'Evaluate Composer plans against a fixed request set';

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
        config(['bureaucracy_privacy.daily_limits.'.ProcessingPurpose::ComposerParse->value => 100000]);

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
        try {
            $permit = method_exists($parser, 'processingContext')
                ? app(ProcessingConsentStore::class)->grant($user, ProcessingPurpose::ComposerParse,
                    $parser::processingContext($request['text'], $profile), $this->acceptance(ProcessingPurpose::ComposerParse))
                : null;
            $parsed = $parser->parse($request['text'], $profile, $now, $permit);
            $routed = app(Answers::class)->route($user, $request['text'], $now, app(WorkspaceStore::class)->read($user)['focus'] ?? null);
        } catch (Throwable $e) {
            return $row + ['error' => 'parse: '.class_basename($e).': '.Str::limit($e->getMessage(), 200)];
        }
        $row['parse_ms'] = (int) ((hrtime(true) - $started) / 1e6);
        $row['parse'] = ['intent' => $parsed->intent->value, 'source' => $parsed->source, 'kind' => $routed['kind'], 'constraints' => $parsed->plan?->toArray()];
        // The same rule ComposerController::parse() applies.
        $answered = Answers::handles($routed, $parsed->intent);
        $row['routed'] = $answered ? 'answer' : ($parsed->intent === PromptIntent::PlanDay && $parsed->plan !== null ? 'compose' : 'reply:'.$parsed->intent->value);

        if ($row['routed'] !== 'compose') {
            return $row + ['expectations' => $checks->expectations($request['expect'], [], null), 'gates' => array_fill_keys(PlanChecks::GATES, [])];
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
            'gates' => $gated['gates'],
            'expectations' => $checks->expectations($request['expect'], $slots, $constraints),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function summarise(array $results): array
    {
        $gates = array_fill_keys(PlanChecks::GATES, 0);
        $expect = [];
        $routed = [];
        $sources = [];
        $latency = [];
        foreach ($results as $r) {
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
        $pick = fn (float $q): ?int => $latency === [] ? null : $latency[(int) floor($q * (count($latency) - 1))];
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
            'parse_source' => $sources,
            'latency_ms' => ['p50' => $pick(0.5), 'p95' => $pick(0.95)],
        ];
    }

    private function report(array $summary, array $results): void
    {
        $this->info(sprintf('%d requests · %d fully passing · %d errors · gates %d (%s)',
            $summary['requests'], $summary['requests_fully_passing'], $summary['errors'], $summary['gates_total'],
            collect($summary['gates'])->map(fn (int $n, string $g): string => "{$g} {$n}")->implode(', ')));
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
        $user = User::query()->firstOrNew(['email' => "composer-eval+{$request['persona']}@example.invalid"]);
        $user->forceFill([
            'name' => 'Composer eval · '.$request['persona'],
            'password' => $user->password ?? Hash::make(Str::random(40)),
            'email_verified_at' => $user->email_verified_at ?? now(),
            'veedel' => $request['home_veedel'],
            'situation' => self::SITUATIONS[$request['persona']] ?? Situation::Other,
        ])->save();

        return $user;
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
                return ['available' => true, 'temperature' => 12, 'precipitation' => $this->rain ? 1.2 : 0, 'icon' => $this->rain ? 'rain' : 'clear', 'label' => $this->rain ? 'Rain' : 'Clear'];
            }

            public function getForecast(float $lat = 50.9375, float $lng = 6.9603): array
            {
                return ['available' => true, 'rain_soon' => $this->rain, 'rain_starts' => $this->rain ? 'now' : null, 'rain_summary' => $this->rain ? 'Rain for the next few hours' : 'Dry for the next few hours'];
            }
        });
    }
}
