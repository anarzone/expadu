<?php

namespace App\Console\Commands;

use App\Support\PerfLogger;
use Illuminate\Console\Command;

class PerfReportCommand extends Command
{
    protected $signature = 'perf:report {--since=24 : Hours of history to include} {--top=30 : Max rows to display} {--sort=p95 : Column to sort by (count|p50|p95|p99|max)}';

    protected $description = 'Show p50/p95/p99 latency per route and external service from PerfLogger';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('since'));
        $sinceTs = now()->subHours($hours)->timestamp;
        $sort = (string) $this->option('sort');

        $rows = [];
        foreach (PerfLogger::keys() as $key) {
            $entries = PerfLogger::entries($key, $sinceTs);
            if (empty($entries)) {
                continue;
            }

            $mses = array_values(array_filter(array_column($entries, 'ms'), fn ($v) => is_numeric($v)));
            if (empty($mses)) {
                continue;
            }

            sort($mses);
            $n = count($mses);
            $okCount = count(array_filter(array_column($entries, 'ok'), fn ($v) => (int) $v === 1));

            $rows[] = [
                'key' => $key,
                'count' => $n,
                'ok' => $n > 0 ? round(($okCount / $n) * 100).'%' : '-',
                'p50' => $mses[(int) floor($n * 0.5)] ?? 0,
                'p95' => $mses[min($n - 1, (int) floor($n * 0.95))] ?? 0,
                'p99' => $mses[min($n - 1, (int) floor($n * 0.99))] ?? 0,
                'max' => max($mses),
            ];
        }

        if (empty($rows)) {
            $this->info("No perf entries in the last {$hours}h.");

            return self::SUCCESS;
        }

        usort($rows, fn ($a, $b) => ($b[$sort] ?? 0) <=> ($a[$sort] ?? 0));
        $rows = array_slice($rows, 0, (int) $this->option('top'));

        $this->info("Perf summary — last {$hours}h, sorted by {$sort} desc");
        $this->table(
            ['key', 'count', 'ok%', 'p50ms', 'p95ms', 'p99ms', 'max'],
            array_map(fn ($r) => [$r['key'], $r['count'], $r['ok'], $r['p50'], $r['p95'], $r['p99'], $r['max']], $rows)
        );

        return self::SUCCESS;
    }
}
