<?php

namespace App\Console\Commands\Events;

use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EventSourceHealth extends Command
{
    protected $signature = 'events:source-health
        {--max-age=36 : Maximum hours since the source was verified}
        {--json : Emit machine-readable JSON lines}';

    protected $description = 'Report event-source inventory, translation coverage, coordinates, and freshness';

    public function handle(): int
    {
        $maxAge = max(1, (int) $this->option('max-age'));
        $sources = Event::query()
            ->selectRaw('source, COUNT(*) AS total')
            ->selectRaw('COUNT(*) FILTER (WHERE starts_at >= NOW() AND status = ?) AS upcoming', ['active'])
            ->selectRaw('COUNT(*) FILTER (WHERE starts_at >= NOW() AND status = ? AND title_en IS NOT NULL AND (description IS NULL OR description_en IS NOT NULL)) AS translated', ['active'])
            ->selectRaw('COUNT(*) FILTER (WHERE starts_at >= NOW() AND status = ? AND location IS NOT NULL) AS located', ['active'])
            ->selectRaw('MAX(verified_at) AS latest_verified_at')
            ->whereNotNull('source')
            ->groupBy('source')
            ->orderBy('source')
            ->get();

        if (! $sources->contains('source', 'stadt-koeln.de')) {
            $sources->push((object) [
                'source' => 'stadt-koeln.de',
                'total' => 0,
                'upcoming' => 0,
                'translated' => 0,
                'located' => 0,
                'latest_verified_at' => null,
            ]);
        }

        $officialHealthy = false;
        foreach ($sources as $source) {
            $run = $source->source === 'stadt-koeln.de' ? Cache::get('events:source-run:stadt-koeln.de') : null;
            $completedAt = is_array($run) && isset($run['completed_at']) ? Carbon::parse($run['completed_at']) : null;
            $total = (int) $source->total;
            $upcoming = (int) $source->upcoming;
            $translationCoverage = $upcoming > 0 ? (int) $source->translated / $upcoming : 0.0;
            $locationCoverage = $upcoming > 0 ? (int) $source->located / $upcoming : 0.0;
            $status = match (true) {
                $source->source !== 'stadt-koeln.de' => 'informational',
                ! is_array($run) || ($run['status'] ?? null) !== 'succeeded' => 'missing',
                ! $completedAt || $completedAt->lessThan(now()->subHours($maxAge)) => 'stale',
                $upcoming < 1 || $translationCoverage < 0.8 || $locationCoverage < 0.4 => 'degraded',
                default => 'healthy',
            };
            $row = [
                'source' => $source->source,
                'status' => $status,
                'total' => $total,
                'upcoming' => $upcoming,
                'translated' => (int) $source->translated,
                'located' => (int) $source->located,
                'translation_coverage' => round($translationCoverage, 3),
                'location_coverage' => round($locationCoverage, 3),
                'latest_success_at' => $completedAt?->toIso8601String(),
            ];

            if ($this->option('json')) {
                $this->line((string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->line(sprintf(
                    '%s: %s; total=%d upcoming=%d translated=%d located=%d verified=%s',
                    $row['source'],
                    $row['status'],
                    $row['total'],
                    $row['upcoming'],
                    $row['translated'],
                    $row['located'],
                    $row['latest_success_at'] ?? 'never',
                ));
            }

            if ($source->source === 'stadt-koeln.de') {
                $officialHealthy = $status === 'healthy';
            }
        }

        if (! $officialHealthy) {
            Log::error('Official event source health check failed', ['source' => 'stadt-koeln.de', 'max_age_hours' => $maxAge]);
        }

        return $officialHealthy ? self::SUCCESS : self::FAILURE;
    }
}
