<?php

namespace App\Services;

use App\Models\CityNews;
use App\Models\NoticeBrief;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Aggregates disruption data from multiple sources:
 * 1. KVB Open Data API — live elevator/escalator disruptions
 * 2. city_news table — KVB's live service page and news, scraped
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
     * Open KVB notices: the live service page and KVB news, newest first
     * within each severity.
     *
     * @return Collection<int, CityNews>
     */
    public function openNotices(): Collection
    {
        return CityNews::query()
            ->where('category', 'transit')
            // Open: a dated notice until its end, however long the works run;
            // an undated one for 30 days from when it was published.
            ->where(fn ($q) => $q->where('expires_at', '>', now())
                ->orWhere(fn ($undated) => $undated->whereNull('expires_at')->where('published_at', '>', now()->subDays(30))))
            // Every open notice: a cap left most of KVB's list out, and the
            // ones that rotated back in were announced again as new.
            ->orderByRaw("case severity when 'critical' then 4 when 'major' then 3 when 'moderate' then 2 else 1 end desc")
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Line disruptions from scraped KVB aktuelles/news, each with its English
     * brief when one has been written (null otherwise: show the notice as is).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLineDisruptions(): array
    {
        $notices = $this->openNotices();
        $briefs = NoticeBrief::query()
            ->where('locale', 'en')
            ->whereIn('input_hash', $notices->map(fn (CityNews $n) => NoticeBrief::hashFor((string) $n->title, $n->summary)))
            ->get()
            ->keyBy('input_hash');

        return $notices
            ->map(function (CityNews $n) use ($briefs) {
                $brief = $briefs->get(NoticeBrief::hashFor((string) $n->title, $n->summary));

                return [
                    'id' => 'news_'.$n->id,
                    'title' => $n->title,
                    'description' => $n->summary ?? '',
                    'severity' => $n->severity ?? 'minor',
                    'type' => 'line',
                    'affected_lines' => $n->affected_lines ?? [],
                    // A live KVB notice's published_at is when it was last seen
                    // (every scrape); it started when it was first seen.
                    'started_at' => ($n->source === 'kvb-betriebslage' ? $n->created_at : $n->published_at)->toIso8601String(),
                    'estimated_end' => $n->expires_at?->toIso8601String(),
                    'source' => $n->source,
                    // KVB's own page for it: the German source, one tap from the brief.
                    'url' => self::kvbPage($n->source_url),
                    'brief' => $brief ? ['headline' => $brief->headline, 'text' => $brief->body !== '' ? $brief->body : null, 'impact' => $brief->impact] : null,
                ];
            })
            ->all();
    }

    /**
     * KVB's page a notice was read from; null for anyone else's (a city press
     * release is not "KVB's notice"). The scraper's `#…` anchors only keep its
     * rows apart; the page itself has no such element.
     */
    public static function kvbPage(?string $url): ?string
    {
        $page = $url === null ? '' : strtok($url, '#');
        $host = is_string($page) && str_starts_with($page, 'https://') ? parse_url($page, PHP_URL_HOST) : null;

        return is_string($host) && preg_match('/(^|\.)kvb\.(koeln|de)$/', $host) === 1 ? $page : null;
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
