<?php

namespace App\Services;

use App\Models\CityNews;
use Illuminate\Support\Facades\Cache;

/**
 * Aggregates disruption data from multiple sources:
 * 1. KVB Open Data API — live elevator/escalator disruptions
 * 2. city_news table — scraped line disruptions from KVB aktuelles
 *
 * Feeds the ContextEngine disruption pipeline and transit fallbacks.
 */
class DisruptionService
{
    /**
     * All active disruptions — line disruptions + accessibility issues.
     *
     * @return array<int, array{id: string, title: string, description: string, severity: string, type: string, affected_lines: string[], started_at: string, estimated_end: string|null, source: string}>
     */
    public function getActiveDisruptions(): array
    {
        return Cache::remember('active_disruptions', 300, function () {
            return array_merge(
                $this->getLineDisruptions(),
                $this->getAccessibilityDisruptions(),
            );
        });
    }

    /**
     * Line disruptions from scraped KVB aktuelles/news.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLineDisruptions(): array
    {
        return CityNews::query()
            ->where('category', 'transit')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where('published_at', '>', now()->subDays(30))
            ->orderByDesc('published_at')
            ->limit(15)
            ->get()
            ->map(fn (CityNews $n) => [
                'id' => 'news_'.$n->id,
                'title' => $n->title,
                'description' => $n->summary ?? '',
                'severity' => $n->severity ?? 'minor',
                'type' => 'line',
                'affected_lines' => $n->affected_lines ?? [],
                'started_at' => $n->published_at->toIso8601String(),
                'estimated_end' => $n->expires_at?->toIso8601String(),
                'source' => $n->source,
            ])
            ->all();
    }

    /**
     * Live accessibility disruptions from KVB Open Data API.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAccessibilityDisruptions(): array
    {
        $kvb = app(KvbApiService::class);
        $items = $kvb->getAccessibilityDisruptions();

        return collect($items)->map(fn (array $d) => [
            'id' => 'kvb_'.$d['type'].'_'.$d['id'],
            'title' => $d['type'] === 'elevator'
                ? "Elevator out: {$d['stop_name']}"
                : "Escalator out: {$d['stop_name']}",
            'description' => $d['label'],
            'severity' => 'minor',
            'type' => 'accessibility',
            'affected_lines' => [],
            'started_at' => $d['timestamp'] ?: now()->toIso8601String(),
            'estimated_end' => null,
            'source' => 'kvb_api',
        ])->all();
    }

    /**
     * Check if specific lines are disrupted.
     *
     * @return array<string, string> line => severity
     */
    public function getDisruptedLines(): array
    {
        $disruptions = $this->getLineDisruptions();
        $lines = [];

        foreach ($disruptions as $d) {
            foreach ($d['affected_lines'] ?? [] as $line) {
                // Keep the highest severity per line
                $current = $lines[$line] ?? 'none';
                if ($this->severityRank($d['severity']) > $this->severityRank($current)) {
                    $lines[$line] = $d['severity'];
                }
            }
        }

        return $lines;
    }

    /**
     * Check if a specific stop has accessibility issues.
     *
     * @return array<int, array{type: string, label: string}>
     */
    public function getStopAccessibilityIssues(string $stopName): array
    {
        $kvb = app(KvbApiService::class);

        return collect($kvb->getAccessibilityDisruptions())
            ->filter(fn (array $d) => str_contains(mb_strtolower($d['stop_name']), mb_strtolower($stopName)))
            ->map(fn (array $d) => [
                'type' => $d['type'],
                'label' => $d['label'],
            ])
            ->values()
            ->all();
    }

    private function severityRank(string $severity): int
    {
        return match ($severity) {
            'critical' => 3,
            'major' => 2,
            'minor' => 1,
            default => 0,
        };
    }
}
