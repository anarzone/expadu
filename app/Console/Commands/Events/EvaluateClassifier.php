<?php

namespace App\Console\Commands\Events;

use App\Ai\UsageMeter;
use App\Models\Event;
use App\Services\AnthropicEventClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * The event classifier (every new listing: translation, summary, category, language, chips and
 * what it's mainly for) on the fixed sensitivity test list, for comparing models and effort:
 * how the listings are read for what personalisation never learns from (precision and recall,
 * as events:sensitive-eval), how many answers come back whole, and the categories it gives, so
 * runs can be compared with each other. Nothing is saved. Every run spends real money.
 */
class EvaluateClassifier extends Command
{
    protected $signature = 'events:classify-eval
        {--model= : Run on this model instead (a comparison; see AiRequest)}
        {--effort= : low, medium, high, xhigh or max}
        {--max-tokens= : Room for each reply, at least}
        {--set=tests/Fixtures/events/sensitive-eval.json}
        {--label=run}';

    protected $description = 'Run the event classifier on the fixed test list and score it';

    public function handle(AnthropicEventClassifier $classifier): int
    {
        UsageMeter::override($this->option('model') ?: null, $this->option('effort') ?: null, $this->option('max-tokens') ? (int) $this->option('max-tokens') : null);
        $meter = (new UsageMeter)->start();
        $cases = json_decode((string) file_get_contents(base_path((string) $this->option('set'))), true, flags: JSON_THROW_ON_ERROR)['cases'];
        $rows = [];
        foreach ($cases as $case) {
            $event = new Event(['title' => $case['title'], 'description' => $case['description'], 'location_name' => $case['venue']]);
            $event->starts_at = CarbonImmutable::parse('2026-11-14 19:00', 'Europe/Berlin');
            try {
                $result = $classifier->classify($event);
                $rows[] = ['id' => $case['id'], 'expected' => $case['expected'], 'borderline' => $case['borderline'], 'read' => $result['sensitive_kind'] ?? 'none',
                    'category' => $result['category'], 'language' => $result['language'], 'chips' => $result['chips'], 'confidence' => $result['confidence']];
            } catch (Throwable $e) {
                $rows[] = ['id' => $case['id'], 'error' => class_basename($e).': '.mb_substr($e->getMessage(), 0, 120)];
            }
        }

        $scored = collect($rows)->filter(fn (array $r) => ! isset($r['error']) && ! $r['borderline']);
        $tp = $scored->filter(fn ($r) => $r['expected'] !== 'none' && $r['read'] !== 'none')->count();
        $fp = $scored->filter(fn ($r) => $r['expected'] === 'none' && $r['read'] !== 'none')->count();
        $fn = $scored->filter(fn ($r) => $r['expected'] !== 'none' && $r['read'] === 'none')->count();
        $summary = ['label' => $this->option('label'), 'model' => $this->option('model') ?: (string) config('services.anthropic.model'),
            'effort' => $this->option('effort') ?: (string) config('services.anthropic.effort'),
            'listings' => count($rows), 'errors' => collect($rows)->whereNotNull('error')->count(),
            'precision' => $tp + $fp === 0 ? 1.0 : round($tp / ($tp + $fp), 3), 'recall' => $tp + $fn === 0 ? 1.0 : round($tp / ($tp + $fn), 3),
            'usage' => $meter->summary()];
        $path = storage_path('app/evals/classify-'.$this->option('label').'-'.now()->format('Ymd-His').'.json');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, (string) json_encode(['summary' => $summary, 'rows' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line(json_encode($summary));
        foreach ($rows as $row) {
            if (isset($row['error'])) {
                $this->line("  #{$row['id']} {$row['error']}");
            } elseif (! $row['borderline'] && $row['read'] !== $row['expected']) {
                $this->line("  #{$row['id']} expected {$row['expected']}, read {$row['read']}");
            }
        }
        $this->line("Report: {$path}");

        return self::SUCCESS;
    }
}
