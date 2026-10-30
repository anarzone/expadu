<?php

namespace App\Console\Commands\Events;

use App\Ai\LlmCalls;
use App\Services\ListingSorter;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * How well listings are read for what they're mainly for (App\Personalisation\Sensitive), on a
 * fixed test list, through the same request events:recategorise sends (ListingSorter). Scores
 * precision (of the listings marked sensitive, how many are) and recall (of the sensitive ones,
 * how many were marked) on the cases that aren't borderline, and whether the kind is right;
 * reports tokens, time and cost for the model and effort given. Every run spends real money.
 */
class EvaluateSensitivity extends Command
{
    private const BATCH = 40;

    protected $signature = 'events:sensitive-eval
        {--model= : The model (default: services.anthropic.model)}
        {--effort= : low, medium, high, xhigh or max (default: the model\'s own)}
        {--runs=1 : Times to run the whole list}
        {--set=tests/Fixtures/events/sensitive-eval.json}
        {--label=run}';

    protected $description = 'Score how listings are read for what personalisation never learns from';

    public function handle(ListingSorter $sorter, LlmCalls $calls): int
    {
        $model = (string) ($this->option('model') ?: config('services.anthropic.model'));
        $effort = $this->option('effort') ?: null;
        $cases = collect(json_decode((string) file_get_contents(base_path((string) $this->option('set'))), true, flags: JSON_THROW_ON_ERROR)['cases']);
        $runs = [];

        foreach (range(1, max(1, (int) $this->option('runs'))) as $run) {
            $read = [];
            $usage = ['input_tokens' => 0, 'output_tokens' => 0];
            $times = [];
            $failed = 0;
            foreach ($cases->chunk(self::BATCH) as $batch) {
                try {
                    $answer = $sorter->sort($batch->map(fn (array $case) => [
                        'id' => $case['id'], 'title' => $case['title'], 'venue' => $case['venue'], 'tags' => $case['tags'], 'description' => $case['description'],
                    ])->values()->all(), $model, $effort);
                } catch (Throwable $exception) {
                    $failed += $batch->count();
                    $this->warn('A batch failed: '.$exception->getMessage());

                    continue;
                }
                $read += $answer['read'];
                $usage['input_tokens'] += $answer['usage']['input_tokens'];
                $usage['output_tokens'] += $answer['usage']['output_tokens'];
                $times[] = $answer['ms'];
            }
            $runs[] = $this->score($cases, $read) + ['failed' => $failed, 'usage' => $usage, 'ms' => $times,
                'cost_usd' => $calls->cost($model, $usage['input_tokens'], $usage['output_tokens'])];
        }

        $summary = [
            'label' => $this->option('label'), 'model' => $model, 'effort' => $effort ?? 'default',
            'cases' => $cases->count(), 'scored' => $cases->where('borderline', false)->count(), 'runs' => count($runs),
            'precision' => round(collect($runs)->avg('precision'), 3), 'recall' => round(collect($runs)->avg('recall'), 3),
            'kind_right' => round(collect($runs)->avg('kind_right'), 3),
            'cost_usd_per_run' => collect($runs)->avg('cost_usd'),
            'ms_per_request' => (int) collect($runs)->pluck('ms')->flatten()->avg(),
            'tokens_per_run' => ['input' => (int) collect($runs)->avg('usage.input_tokens'), 'output' => (int) collect($runs)->avg('usage.output_tokens')],
        ];
        $path = storage_path('app/evals/sensitive-'.$this->option('label').'-'.now()->format('Ymd-His').'.json');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode(['summary' => $summary, 'runs' => $runs], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->table(['model', 'effort', 'precision', 'recall', 'kind right', '$ per run', 'ms / request', 'tokens in / out'], [[
            $model, $summary['effort'], $summary['precision'], $summary['recall'], $summary['kind_right'],
            $summary['cost_usd_per_run'] === null ? 'unpriced' : number_format($summary['cost_usd_per_run'], 4),
            $summary['ms_per_request'], $summary['tokens_per_run']['input'].' / '.$summary['tokens_per_run']['output'],
        ]]);
        foreach ($runs as $index => $run) {
            foreach ($run['wrong'] as $wrong) {
                $this->line(sprintf('  run %d: #%d %s — expected %s, read %s', $index + 1, $wrong['id'], $wrong['title'], $wrong['expected'], $wrong['read']));
            }
        }
        $this->line("Report: {$path}");

        return collect($runs)->sum('failed') === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $cases
     * @param  array<int, array{category: string, sensitive: bool, sensitive_kind: ?string}>  $read
     * @return array<string, mixed>
     */
    private function score($cases, array $read): array
    {
        $tp = $fp = $fn = $tn = $kindRight = 0;
        $wrong = [];
        $borderline = [];
        foreach ($cases as $case) {
            $kind = $read[$case['id']]['sensitive_kind'] ?? null;
            $said = $kind ?? 'none';
            if ($case['borderline']) {
                $borderline[] = ['id' => $case['id'], 'title' => $case['title'], 'read' => $said];

                continue;
            }
            $sensitive = $case['expected'] !== 'none';
            match (true) {
                $sensitive && $kind !== null => $tp++,
                ! $sensitive && $kind !== null => $fp++,
                $sensitive && $kind === null => $fn++,
                default => $tn++,
            };
            if ($sensitive && $kind === $case['expected']) {
                $kindRight++;
            }
            if ($said !== $case['expected']) {
                $wrong[] = ['id' => $case['id'], 'title' => $case['title'], 'expected' => $case['expected'], 'read' => $said];
            }
        }

        return ['tp' => $tp, 'fp' => $fp, 'fn' => $fn, 'tn' => $tn,
            'precision' => $tp + $fp === 0 ? 1.0 : $tp / ($tp + $fp), 'recall' => $tp + $fn === 0 ? 1.0 : $tp / ($tp + $fn),
            'kind_right' => $tp + $fn === 0 ? 1.0 : $kindRight / ($tp + $fn), 'wrong' => $wrong, 'borderline' => $borderline];
    }
}
