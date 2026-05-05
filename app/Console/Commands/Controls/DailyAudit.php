<?php

namespace App\Console\Commands\Controls;

use App\Models\User;
use App\Services\EmbeddingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

/**
 * Section C2 + D — daily controls audit for the recommendation pipeline.
 *
 * Emits a structured JSON snapshot of every metric the validation plan
 * tracks. Exits with status 1 if any threshold is tripped so a cron
 * can flag the run via standard process-monitoring.
 *
 * Run via the scheduler at 04:00. Output also goes to a stamped JSON
 * file under storage/app/controls/ so drift-report can diff weekly.
 */
#[Signature('controls:daily-audit {--silent : Suppress stdout, write file only}')]
#[Description('Daily structured audit of the recommendation pipeline against documented thresholds.')]
class DailyAudit extends Command
{
    /** @var array<string, array{warn: float|int, fail: float|int, direction: 'min'|'max'}> */
    private const THRESHOLDS = [
        'pending_actions_coverage_pct' => ['warn' => 70, 'fail' => 60, 'direction' => 'min'],
        'disruption_match_rate_pct' => ['warn' => 97, 'fail' => 95, 'direction' => 'min'],
        'top_action_mean_score' => ['warn' => 25, 'fail' => 20, 'direction' => 'min'],
        'preference_vector_coverage_pct' => ['warn' => 92, 'fail' => 90, 'direction' => 'min'],
        'embedding_latency_ms' => ['warn' => 150, 'fail' => 200, 'direction' => 'max'],
        'pgvector_query_latency_ms' => ['warn' => 30, 'fail' => 50, 'direction' => 'max'],
        'commute_queue_depth' => ['warn' => 200, 'fail' => 500, 'direction' => 'max'],
    ];

    public function handle(EmbeddingService $embeddings): int
    {
        $metrics = [
            'generated_at' => now()->toIso8601String(),
            'pending_actions_coverage_pct' => $this->pendingActionsCoverage(),
            'disruption_match_rate_pct' => $this->disruptionMatchRate(),
            'top_action_mean_score' => $this->topActionMeanScore(),
            'preference_vector_coverage_pct' => $this->preferenceVectorCoverage(),
            'embedding_latency_ms' => $this->embeddingLatency($embeddings),
            'pgvector_query_latency_ms' => $this->pgvectorLatency(),
            'commute_queue_depth' => $this->commuteQueueDepth(),
            'onboarded_user_count' => User::whereNotNull('onboarded_at')->count(),
        ];

        [$status, $issues] = $this->evaluate($metrics);
        $report = ['status' => $status, 'metrics' => $metrics, 'issues' => $issues];

        $this->persist($report);

        if (! $this->option('silent')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        if ($status !== 'ok') {
            Log::warning('controls:daily-audit tripped thresholds', $report);
        }

        return $status === 'fail' ? self::FAILURE : self::SUCCESS;
    }

    private function pendingActionsCoverage(): float
    {
        $onboarded = User::whereNotNull('onboarded_at')->count();
        if ($onboarded === 0) {
            return 100.0;
        }

        $pattern = config('context_engine.shadow') ? 'pending_actions:*_shadow' : 'pending_actions:*';
        $keys = $this->scanKeys($pattern);
        $shadow = (bool) config('context_engine.shadow');

        $withActions = 0;
        foreach ($keys as $k) {
            $stripped = $this->stripPrefix($k);
            $isShadowKey = str_ends_with($stripped, '_shadow');
            if ($isShadowKey !== $shadow) {
                continue;
            }
            if (Redis::zcard($stripped) > 0) {
                $withActions++;
            }
        }

        return round(100.0 * $withActions / $onboarded, 1);
    }

    private function disruptionMatchRate(): float
    {
        $events = DB::table('city_news')
            ->where('created_at', '>', now()->subDay())
            ->where('category', 'transit')
            ->count();
        if ($events === 0) {
            return 100.0;
        }

        $matches = DB::table('alerts')
            ->where('subtype', 'transit_disruption')
            ->where('created_at', '>', now()->subDay())
            ->count();

        return round(min(100.0, 100.0 * $matches / $events), 1);
    }

    private function topActionMeanScore(): float
    {
        $pattern = config('context_engine.shadow') ? 'pending_actions:*_shadow' : 'pending_actions:*';
        $keys = $this->scanKeys($pattern);
        if (empty($keys)) {
            return 0.0;
        }

        $scores = [];
        foreach (array_slice($keys, 0, 200) as $key) {
            $stripped = $this->stripPrefix($key);
            $top = Redis::zrevrange($stripped, 0, 0, ['WITHSCORES' => true]);
            if (! is_array($top) || empty($top)) {
                continue;
            }
            $scores[] = (float) reset($top);
        }

        if (empty($scores)) {
            return 0.0;
        }

        return round(array_sum($scores) / count($scores), 1);
    }

    private function preferenceVectorCoverage(): float
    {
        $onboarded = User::whereNotNull('onboarded_at')->count();
        if ($onboarded === 0) {
            return 100.0;
        }
        $withVector = DB::table('users')
            ->whereNotNull('onboarded_at')
            ->whereNotNull('preference_vector')
            ->count();

        return round(100.0 * $withVector / $onboarded, 1);
    }

    private function embeddingLatency(EmbeddingService $embeddings): float
    {
        $start = microtime(true);
        $vec = $embeddings->embed('cozy cafe with strong wifi near the river');
        $elapsed = (microtime(true) - $start) * 1000.0;

        return $vec === null ? 9999.0 : round($elapsed, 1);
    }

    private function pgvectorLatency(): float
    {
        try {
            $sample = DB::table('users')
                ->whereNotNull('preference_vector')
                ->orderBy('id')
                ->value('preference_vector');
        } catch (\Throwable) {
            return 0.0;
        }

        if (! $sample) {
            return 0.0;
        }

        $literal = is_string($sample) ? $sample : EmbeddingService::toLiteral($sample);
        $start = microtime(true);
        try {
            DB::select("SELECT id FROM spots WHERE embedding IS NOT NULL ORDER BY embedding <=> '{$literal}'::vector LIMIT 10");
        } catch (\Throwable) {
            return 9999.0;
        }
        $elapsed = (microtime(true) - $start) * 1000.0;

        return round($elapsed, 1);
    }

    private function commuteQueueDepth(): int
    {
        try {
            return (int) DB::table('jobs')->where('queue', 'commute')->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array{0: 'ok'|'warn'|'fail', 1: list<string>}
     */
    private function evaluate(array $metrics): array
    {
        $issues = [];
        $worst = 'ok';

        foreach (self::THRESHOLDS as $key => $cfg) {
            $value = $metrics[$key] ?? null;
            if ($value === null) {
                continue;
            }
            $tripFail = $cfg['direction'] === 'min' ? ($value < $cfg['fail']) : ($value > $cfg['fail']);
            $tripWarn = $cfg['direction'] === 'min' ? ($value < $cfg['warn']) : ($value > $cfg['warn']);

            if ($tripFail) {
                $issues[] = "{$key}={$value} crosses fail threshold ({$cfg['direction']} {$cfg['fail']})";
                $worst = 'fail';
            } elseif ($tripWarn) {
                $issues[] = "{$key}={$value} crosses warn threshold ({$cfg['direction']} {$cfg['warn']})";
                if ($worst === 'ok') {
                    $worst = 'warn';
                }
            }
        }

        return [$worst, $issues];
    }

    /** @param  array<string, mixed>  $report */
    private function persist(array $report): void
    {
        $name = 'controls/audit-'.now()->format('Y-m-d').'.json';
        Storage::disk('local')->put($name, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** @return list<string> */
    private function scanKeys(string $pattern): array
    {
        // phpredis defaults to SCAN_NORETRY where the configured key prefix is
        // NOT auto-applied to MATCH patterns. SCAN_PREFIX makes phpredis prepend
        // the prefix automatically and strip it from results, so we can pass the
        // logical pattern and get back unprefixed keys.
        $client = Redis::connection()->client();
        if (defined('Redis::OPT_SCAN') && method_exists($client, 'setOption')) {
            $client->setOption(\Redis::OPT_SCAN, \Redis::SCAN_PREFIX);
        }

        $cursor = '0';
        $out = [];
        do {
            [$cursor, $batch] = Redis::scan($cursor, ['MATCH' => $pattern, 'COUNT' => 500]);
            foreach ($batch as $k) {
                $out[] = (string) $k;
            }
        } while ($cursor !== '0' && count($out) < 5000);

        return $out;
    }

    private function stripPrefix(string $key): string
    {
        // With SCAN_PREFIX mode set in scanKeys(), phpredis already strips the
        // prefix from returned keys. Keep this helper as a safety net for the
        // (unlikely) case where SCAN_PREFIX wasn't applied — strip if present,
        // pass through if not.
        $prefix = (string) config('database.redis.options.prefix', '');
        if ($prefix && str_starts_with($key, $prefix)) {
            return substr($key, strlen($prefix));
        }

        return $key;
    }
}
