<?php

namespace App\Console\Commands\Notifications;

use App\ContextEngine\BriefWriter;
use App\ContextEngine\DayBriefs;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The brief eval: the AI writes briefs for made-up days (tests/Fixtures/notification-briefs.json)
 * through BriefWriter::compose, as it would a person's, and each is checked: how many of its
 * lines held (the rest were dropped for saying what the items don't), whether the first line says
 * the most important thing, and what it must or mustn't say. Fixtures only: never a person's day,
 * which goes to no AI without their permission.
 */
class EvaluateBriefs extends Command
{
    protected $signature = 'notifications:brief-eval {--runs=1 : Times to run each case} {--only= : Comma-separated case ids}';

    protected $description = 'Have the AI write briefs for made-up days and check every line against its items';

    public function handle(BriefWriter $writer): int
    {
        $cases = json_decode((string) file_get_contents(base_path('tests/Fixtures/notification-briefs.json')), true)['cases'];
        $only = array_filter(explode(',', (string) $this->option('only')));
        $passed = 0;
        $total = 0;

        foreach ($cases as $case) {
            if ($only !== [] && ! in_array($case['id'], $only, true)) {
                continue;
            }
            $items = array_map(fn (array $item, int $i): array => ['id' => 'i'.($i + 1), 'own' => true, 'rank' => $i, ...$item], $case['items'], array_keys($case['items']));
            for ($run = 1; $run <= (int) $this->option('runs'); $run++) {
                $total++;
                $raw = null;
                try {
                    $lines = $writer->compose($items, CarbonImmutable::parse($case['day'], 'Europe/Berlin'), $case['kind'] === 'evening' ? DayBriefs::EVENING : DayBriefs::MORNING,
                        function (array $request) use (&$raw): ?array {
                            return $raw = Http::baseUrl((string) config('services.llm.base_url'))
                                ->withHeaders(['x-api-key' => (string) config('services.llm.key'), 'anthropic-version' => '2023-06-01'])
                                ->timeout(15)->post('/v1/messages', $request)->throw()->json();
                        });
                } catch (Throwable $e) {
                    $this->line("  ERROR {$case['id']} ".class_basename($e));

                    continue;
                }
                $written = collect($raw['content'] ?? [])->firstWhere('type', 'tool_use')['input']['lines'] ?? [];
                $said = implode(' ', $lines ?? []);
                $paperwork = collect($case['items'])->contains('kind', 'paperwork') ? 1 : 0;
                $checks = array_filter([
                    'wrote' => $lines !== null,
                    'all_held' => $lines !== null && count($lines) - $paperwork === count($written),
                    'first' => isset($case['expect']['first']) ? preg_match('/'.$case['expect']['first'].'/iu', $lines[0] ?? '') === 1 : null,
                    'last' => isset($case['expect']['last']) ? preg_match('/'.$case['expect']['last'].'/u', (string) end($lines)) === 1 : null,
                    'says' => isset($case['expect']['says']) ? preg_match('/'.$case['expect']['says'].'/u', $said) === 1 : null,
                    // Each line by itself: two lines side by side link nothing.
                    'not_says' => isset($case['expect']['not_says']) ? collect($lines ?? [])->every(fn (string $line): bool => preg_match('/'.$case['expect']['not_says'].'/iu', $line) !== 1) : null,
                ], fn (?bool $check): bool => $check !== null);
                $failed = array_keys(array_filter($checks, fn (bool $ok): bool => ! $ok));
                $passed += $failed === [] ? 1 : 0;
                $this->line(sprintf('  %s %s | %s', $failed === [] ? 'PASS' : 'FAIL '.implode(',', $failed), $case['id'],
                    $lines === null ? '(template)' : implode(' / ', $lines)).(count($written) > count($lines ?? []) - $paperwork ? ' | dropped: '.implode(' / ', array_diff(array_column($written, 'text'), $lines ?? [])) : ''));
            }
        }
        $this->info("{$passed}/{$total} passed");

        return self::SUCCESS;
    }
}
