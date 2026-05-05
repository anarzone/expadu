<?php

namespace App\Console\Commands\Controls;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Section D4 — weekly drift detection.
 *
 * Reads the last 28 days of daily-audit JSON snapshots and reports the
 * per-metric % delta between this week's median and the prior 3 weeks'
 * median. Anything moving > 30% gets called out in the issues array.
 *
 * Run weekly Mondays 04:30 (after Monday's daily-audit at 04:00 has run).
 *
 * Also rolls up notification-volume drift (per subtype) directly from
 * the database since that doesn't appear in the audit snapshots.
 */
#[Signature('controls:drift-report {--silent : Suppress stdout, write file only}')]
#[Description('Compare this week metrics against the prior 4-week median to detect drift > 30%.')]
class DriftReport extends Command
{
    private const DRIFT_THRESHOLD_PCT = 30.0;

    public function handle(): int
    {
        $auditMetrics = ['pending_actions_coverage_pct', 'top_action_mean_score', 'preference_vector_coverage_pct', 'embedding_latency_ms', 'pgvector_query_latency_ms'];

        $thisWeek = $this->loadAuditWindow(0, 7);
        $priorWindow = $this->loadAuditWindow(7, 28);

        $report = [
            'generated_at' => now()->toIso8601String(),
            'this_week_days' => count($thisWeek),
            'prior_window_days' => count($priorWindow),
            'audit_drift' => [],
            'notification_drift' => $this->notificationVolumeDrift(),
            'issues' => [],
        ];

        foreach ($auditMetrics as $metric) {
            $now = $this->median(array_column($thisWeek, $metric));
            $base = $this->median(array_column($priorWindow, $metric));
            $delta = $base > 0.0 ? round(100.0 * ($now - $base) / $base, 1) : 0.0;

            $report['audit_drift'][$metric] = [
                'this_week_median' => $now,
                'prior_median' => $base,
                'delta_pct' => $delta,
            ];

            if (abs($delta) >= self::DRIFT_THRESHOLD_PCT && $base > 0.0) {
                $report['issues'][] = "{$metric} drifted {$delta}% (now={$now}, prior={$base})";
            }
        }

        foreach ($report['notification_drift'] as $subtype => $row) {
            if (abs($row['delta_pct']) >= self::DRIFT_THRESHOLD_PCT && $row['prior_median'] > 0) {
                $report['issues'][] = "notifications.{$subtype} drifted {$row['delta_pct']}% (now={$row['this_week_median']}, prior={$row['prior_median']})";
            }
        }

        $report['status'] = empty($report['issues']) ? 'ok' : 'drift';
        $this->persist($report);

        if (! $this->option('silent')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }

    /**
     * Load all daily-audit JSON snapshots whose date is in [today-fromDays, today-fromDays-windowDays).
     *
     * @return list<array<string, mixed>>
     */
    private function loadAuditWindow(int $fromDays, int $totalDays): array
    {
        $rows = [];
        for ($d = $fromDays; $d < $totalDays; $d++) {
            $date = now()->subDays($d)->format('Y-m-d');
            $path = "controls/audit-{$date}.json";
            if (! Storage::disk('local')->exists($path)) {
                continue;
            }
            $raw = json_decode((string) Storage::disk('local')->get($path), true);
            if (is_array($raw) && isset($raw['metrics']) && is_array($raw['metrics'])) {
                $rows[] = $raw['metrics'];
            }
        }

        return $rows;
    }

    /** @return array<string, array{this_week_median: float, prior_median: float, delta_pct: float}> */
    private function notificationVolumeDrift(): array
    {
        try {
            $thisWeek = DB::table('alerts')
                ->where('created_at', '>', now()->subDays(7))
                ->selectRaw('subtype, count(*) as c')
                ->groupBy('subtype')
                ->pluck('c', 'subtype');

            $priorWindow = DB::table('alerts')
                ->whereBetween('created_at', [now()->subDays(28), now()->subDays(7)])
                ->selectRaw('subtype, count(*) as c')
                ->groupBy('subtype')
                ->pluck('c', 'subtype');
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        $allSubtypes = $thisWeek->keys()->merge($priorWindow->keys())->unique();
        foreach ($allSubtypes as $subtype) {
            $now = (float) ($thisWeek[$subtype] ?? 0);
            // 7d this-week vs 21d prior-3-weeks: normalize to per-7-day median
            $priorTotal = (float) ($priorWindow[$subtype] ?? 0);
            $base = $priorTotal / 3.0;
            $delta = $base > 0.0 ? round(100.0 * ($now - $base) / $base, 1) : 0.0;

            $out[$subtype] = [
                'this_week_median' => $now,
                'prior_median' => round($base, 1),
                'delta_pct' => $delta,
            ];
        }

        return $out;
    }

    /** @param  list<int|float|null>  $values */
    private function median(array $values): float
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null && is_numeric($v)));
        if (empty($values)) {
            return 0.0;
        }
        sort($values);
        $count = count($values);
        $mid = (int) ($count / 2);

        return $count % 2 === 0
            ? round(($values[$mid - 1] + $values[$mid]) / 2.0, 1)
            : (float) round((float) $values[$mid], 1);
    }

    /** @param  array<string, mixed>  $report */
    private function persist(array $report): void
    {
        $name = 'controls/drift-'.now()->format('Y-m-d').'.json';
        Storage::disk('local')->put($name, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
