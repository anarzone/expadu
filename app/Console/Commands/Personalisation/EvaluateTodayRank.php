<?php

namespace App\Console\Commands\Personalisation;

use App\Personalisation\TodayRanker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The Today ranking eval: the AI orders made-up days (tests/Fixtures/today-rank.json) through
 * TodayRanker::compose, as it would a person's, and each is checked: what comes first (and last),
 * and that it wrote no why when it knows nothing of them. A reply whose why doesn't hold comes back
 * as Today's own order and is counted. Fixtures only: never a person's data.
 */
class EvaluateTodayRank extends Command
{
    protected $signature = 'today:rank-eval {--runs=1 : Times to run each case} {--only= : Comma-separated case ids}';

    protected $description = 'Have the AI order made-up Today candidates and check the order and every why';

    public function handle(TodayRanker $ranker): int
    {
        $cases = json_decode((string) file_get_contents(base_path('tests/Fixtures/today-rank.json')), true)['cases'];
        $only = array_filter(explode(',', (string) $this->option('only')));
        [$passed, $total] = [0, 0];

        foreach ($cases as $case) {
            if ($only !== [] && ! in_array($case['id'], $only, true)) {
                continue;
            }
            for ($run = 1; $run <= (int) $this->option('runs'); $run++) {
                $total++;
                try {
                    $order = $ranker->compose(['day' => '2026-11-14 15:00', 'facts' => $case['facts'], 'recent' => $case['recent'], 'candidates' => $case['candidates']],
                        fn (array $request): ?array => Http::baseUrl((string) config('services.llm.base_url'))
                            ->withHeaders(['x-api-key' => (string) config('services.llm.key'), 'anthropic-version' => '2023-06-01'])
                            ->timeout(15)->post('/v1/messages', $request)->throw()->json());
                } catch (Throwable $e) {
                    $this->line("  ERROR {$case['id']} ".class_basename($e));

                    continue;
                }
                $expect = $case['expect'];
                $checks = array_filter([
                    'ordered' => ($expect['no_why'] ?? false) ? null : $order !== null,
                    'first_event' => isset($expect['first_event']) ? ($order['events'][0] ?? null) === $expect['first_event'] : null,
                    'first_place' => isset($expect['first_place']) ? ($order['places'][0] ?? null) === $expect['first_place'] : null,
                    'last_place' => isset($expect['last_place']) ? (array_slice($order['places'] ?? [], -1)[0] ?? null) === $expect['last_place'] : null,
                    'no_why' => ($expect['no_why'] ?? false) ? ($order === null || $order['why'] === []) : null,
                ], fn (?bool $check): bool => $check !== null);
                $failed = array_keys(array_filter($checks, fn (bool $ok): bool => ! $ok));
                $passed += $failed === [] ? 1 : 0;
                $this->line(sprintf('  %s %s | %s', $failed === [] ? 'PASS' : 'FAIL '.implode(',', $failed), $case['id'], $order === null
                    ? "(Today's own order: a why didn't hold, or no reply)"
                    : 'events '.implode(' ', $order['events']).' | places '.implode(' ', $order['places']).' | '.json_encode($order['why'], JSON_UNESCAPED_UNICODE)));
            }
        }
        $this->info("{$passed}/{$total} passed");

        return self::SUCCESS;
    }
}
