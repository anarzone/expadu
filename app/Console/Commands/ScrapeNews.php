<?php

namespace App\Console\Commands;

use App\Models\CityNews;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScrapeNews extends Command
{
    protected $signature = 'news:scrape';

    protected $description = 'Scrape city news and transit updates relevant to expats in Cologne';

    public function handle(): int
    {
        $created = 0;

        $betriebslageCount = $this->scrapeKvbBetriebslage();
        $created += $betriebslageCount;
        $this->info("KVB betriebslage: {$betriebslageCount}");

        $created += $this->scrapeKvbAktuelles();
        $this->info("KVB aktuelles: {$created}");

        $stadtCount = $this->scrapeStadtKoelnNews();
        $created += $stadtCount;
        $this->info("stadt-koeln.de news: {$stadtCount}");

        // Clean up expired news older than 30 days
        $deleted = CityNews::where('published_at', '<', now()->subDays(30))->delete();
        if ($deleted > 0) {
            $this->info("Cleaned up {$deleted} old news items");
        }

        $this->info("Total: {$created} news items scraped");

        return self::SUCCESS;
    }

    /**
     * Scrape KVB betriebslage page — real-time per-line disruption status.
     * Structure: <ul id="stoer_bahn"><li class="bahn linie1"><h3>1</h3><strong>title</strong><div class="weitereinfos">details</div></li>
     */
    protected function scrapeKvbBetriebslage(): int
    {
        $created = 0;

        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Expadu/1.0)'])
                ->get('https://www.kvb.koeln/fahrtinfo/betriebslage/index.html');

            if (! $response->successful()) {
                Log::info('news:scrape — KVB betriebslage returned '.$response->status());

                return 0;
            }

            $html = $response->body();
            if (! mb_check_encoding($html, 'UTF-8')) {
                $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
            }

            // Parse each <li class="list-group-item linieXX bahn/bus"> entry
            // Use ungreedy match with nested content
            preg_match_all('/<li\s+class="list-group-item\s+linie(\w+)\s+(bahn|bus)"[^>]*>(.*?)<\/li>/si', $html, $entries, PREG_SET_ORDER);

            foreach ($entries as $entry) {
                $line = trim($entry[1]);
                $isBus = $entry[2] === 'bus';
                $content = $entry[3];

                if (! $line || mb_strlen($line) > 10) {
                    continue;
                }

                // Extract title from <b> tag (not <strong>)
                $title = '';
                if (preg_match('/<b[^>]*>(.*?)<\/b>/si', $content, $titleMatch)) {
                    $title = trim(strip_tags(html_entity_decode($titleMatch[1], ENT_QUOTES, 'UTF-8')));
                }

                if (! $title || mb_strlen($title) < 5) {
                    continue;
                }

                // Extract details from .weitereinfos div
                $details = '';
                if (preg_match('/<div\s+class="weitereinfos[^"]*"[^>]*>(.*?)<\/div>/si', $content, $detailMatch)) {
                    $details = trim(preg_replace('/\s+/', ' ', strip_tags(html_entity_decode($detailMatch[1], ENT_QUOTES, 'UTF-8'))));
                }

                $sourceUrl = 'https://www.kvb.koeln/fahrtinfo/betriebslage/#'.md5($line.'_'.$title);

                // Upsert — betriebslage is live status, update existing entries
                $existing = CityNews::where('source_url', $sourceUrl)->first();
                if ($existing) {
                    $existing->update([
                        'summary' => $details ?: $existing->summary,
                        'published_at' => now(),
                    ]);

                    continue;
                }

                $severity = $this->inferSeverity($title.' '.$details);

                CityNews::create([
                    'title' => mb_substr(($isBus ? "Bus {$line}: " : "Linie {$line}: ").$title, 0, 255),
                    'summary' => $details ?: null,
                    'source' => 'kvb-betriebslage',
                    'source_url' => $sourceUrl,
                    'category' => 'transit',
                    'relevance' => 'transit',
                    'affected_lines' => [$line],
                    'severity' => $severity,
                    'published_at' => now(),
                    'expires_at' => now()->addDays(3), // betriebslage items are short-lived
                ]);

                $created++;
            }
        } catch (\Exception $e) {
            Log::warning('news:scrape — KVB betriebslage error: '.$e->getMessage());
        }

        return $created;
    }

    /**
     * Scrape KVB /aktuelles/ page — primary source for line disruptions with date ranges.
     */
    protected function scrapeKvbAktuelles(): int
    {
        $created = 0;

        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Expadu/1.0)'])
                ->get('https://www.kvb.koeln/aktuelles/');

            if (! $response->successful()) {
                Log::info('news:scrape — KVB aktuelles returned '.$response->status());

                return 0;
            }

            $html = $response->body();
            if (! mb_check_encoding($html, 'UTF-8')) {
                $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
            }

            // Extract headings which contain disruption info
            preg_match_all('/<h[234][^>]*>(.*?)<\/h[234]>/si', $html, $headings);

            foreach ($headings[1] ?? [] as $raw) {
                $title = trim(strip_tags(html_entity_decode($raw, ENT_QUOTES, 'UTF-8')));

                if (mb_strlen($title) < 10 || mb_strlen($title) > 200) {
                    continue;
                }

                // Skip navigation/generic headings
                if (preg_match('/^(KVB|Menü|Navigation|Suche|Kontakt|Startseite|Fahrtinfo|Fahrplan|Jetzt|Shop|eezy|Liebling)/i', $title)) {
                    continue;
                }

                // Skip non-transit promotional content
                if (preg_match('/^(Die K[öo]ln|Pop Up|Deutschlandticket|Gondel|Vorteile|Kölner Lichter|Seilbahn)/i', $title)) {
                    continue;
                }

                // Must contain transit-related keywords
                if (! preg_match('/Linie|Trennung|Sanierung|Sperrung|Umleitung|Einschränkung|Baustelle|Ersatz|Haltestelle|Stadtbahn|Bus\b|Bahn/i', $title)) {
                    continue;
                }

                $url = 'https://www.kvb.koeln/aktuelles/#'.md5($title);

                if (CityNews::where('source_url', $url)->exists()) {
                    continue;
                }

                // Parse date range (e.g., "28.03. - 13.04.2026: Trennung der Linien 1 + 9")
                $dateRange = $this->parseDateRange($title);
                $lines = $this->extractLines($title);
                $severity = $this->inferSeverity($title);

                CityNews::create([
                    'title' => mb_substr($title, 0, 255),
                    'summary' => $this->buildSummary($title, $lines),
                    'source' => 'kvb',
                    'source_url' => $url,
                    'category' => 'transit',
                    'relevance' => 'transit',
                    'affected_lines' => $lines ?: null,
                    'severity' => $severity,
                    'published_at' => $dateRange['start'] ?? now(),
                    'expires_at' => $dateRange['end'],
                ]);

                $created++;

                if ($created >= 15) {
                    break;
                }
            }
        } catch (\Exception $e) {
            Log::warning('news:scrape — KVB aktuelles error: '.$e->getMessage());
        }

        return $created;
    }

    /**
     * Scrape news from Stadt Köln press page.
     */
    protected function scrapeStadtKoelnNews(): int
    {
        $created = 0;

        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Expadu/1.0 (City News for Expats)'])
                ->get('https://www.stadt-koeln.de/politik-und-verwaltung/presse/mitteilungen');

            if (! $response->successful()) {
                return 0;
            }

            $html = $response->body();

            preg_match_all('/<a[^>]*href="(\/politik-und-verwaltung\/presse\/mitteilungen\/[^"]+)"[^>]*>(.*?)<\/a>/si', $html, $matches);

            $seen = [];
            for ($i = 0; $i < count($matches[1] ?? []); $i++) {
                $link = 'https://www.stadt-koeln.de'.trim($matches[1][$i]);
                $title = trim(strip_tags($matches[2][$i]));

                if (! $title || mb_strlen($title) < 10 || mb_strlen($title) > 200) {
                    continue;
                }

                if (isset($seen[$link]) || CityNews::where('source_url', $link)->exists()) {
                    continue;
                }
                $seen[$link] = true;

                $category = $this->categorise($title, '');
                $relevance = $this->determineRelevance($title, '');

                if ($relevance === 'skip') {
                    continue;
                }

                CityNews::create([
                    'title' => mb_substr($title, 0, 255),
                    'summary' => null,
                    'source' => 'stadt-koeln',
                    'source_url' => $link,
                    'category' => $category,
                    'relevance' => $relevance,
                    'published_at' => now(),
                    'expires_at' => now()->addDays(14),
                ]);

                $created++;

                if ($created >= 15) {
                    break;
                }
            }
        } catch (\Exception $e) {
            Log::warning('news:scrape — stadt-koeln.de error: '.$e->getMessage());
        }

        return $created;
    }

    /**
     * Parse date range from KVB heading like "28.03. - 13.04.2026: ..."
     *
     * @return array{start: Carbon|null, end: Carbon|null}
     */
    protected function parseDateRange(string $title): array
    {
        $result = ['start' => null, 'end' => null];

        // Pattern: "DD.MM. - DD.MM.YYYY" or "DD.MM.YYYY - DD.MM.YYYY"
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})?\s*[-–]\s*(\d{1,2})\.(\d{1,2})\.(\d{4})/', $title, $m)) {
            $year = $m[6];
            $startYear = $m[3] ?: $year;

            try {
                $result['start'] = Carbon::createFromFormat('d.m.Y', "{$m[1]}.{$m[2]}.{$startYear}");
                $result['end'] = Carbon::createFromFormat('d.m.Y', "{$m[4]}.{$m[5]}.{$year}")->endOfDay();
            } catch (\Exception) {
                // ignore parse errors
            }
        }
        // Pattern: "DD.MM.YYYY"
        elseif (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $title, $m)) {
            try {
                $result['start'] = Carbon::createFromFormat('d.m.Y', "{$m[1]}.{$m[2]}.{$m[3]}");
                $result['end'] = $result['start']->copy()->addDays(7)->endOfDay();
            } catch (\Exception) {
                // ignore
            }
        }

        return $result;
    }

    /**
     * Extract line numbers from title.
     *
     * @return string[]
     */
    protected function extractLines(string $title): array
    {
        $lines = [];

        // "Linien 1 + 9", "Linie 18", "Linien 1, 7 und 9"
        if (preg_match('/Linien?\s+([\d\s+,und]+)/i', $title, $m)) {
            preg_match_all('/(\d+)/', $m[1], $nums);
            $lines = array_merge($lines, $nums[1] ?? []);
        }

        // "Bus 136", "Linie 142"
        if (preg_match_all('/(?:Bus|Ersatzbus)\s+(\d+)/i', $title, $m)) {
            $lines = array_merge($lines, $m[1]);
        }

        // "S12", "S19"
        if (preg_match_all('/\b(S\d+)\b/', $title, $m)) {
            $lines = array_merge($lines, $m[1]);
        }

        return array_values(array_unique($lines));
    }

    protected function inferSeverity(string $title): string
    {
        $t = mb_strtolower($title);

        // Critical: full closures, strikes, emergencies — always alert
        if (preg_match('/sperrung|vollsperrung|gesperrt|streik|evakuierung|entgleist/i', $t)) {
            return 'critical';
        }

        // Major: service terminations, infrastructure damage — always alert
        if (preg_match('/enden hier|fahrleitungsschaden|ausfall|trennung|getrennt|einschränkung/i', $t)) {
            return 'major';
        }

        // Moderate: planned construction, diversions, congestion — respects quiet hours
        if (preg_match('/baumaßnahme|bauarbeiten|umleitung|sanierung|hohes verkehrsaufkommen|verkehrsbehinderung/i', $t)) {
            return 'moderate';
        }

        return 'minor';
    }

    protected function buildSummary(string $title, array $lines): string
    {
        $parts = [];

        if ($lines) {
            $parts[] = 'Affected: Line '.implode(', ', $lines);
        }

        // Extract the descriptive part after the date/colon
        if (preg_match('/:\s*(.+)$/u', $title, $m)) {
            $parts[] = trim($m[1]);
        }

        return implode(' — ', $parts) ?: 'KVB transit disruption';
    }

    protected function categorise(string $title, string $desc): string
    {
        $text = mb_strtolower($title.' '.$desc);

        if (preg_match('/kvb|tram|bus|bahn|verkehr|linie|haltestelle|umleitung/i', $text)) {
            return 'transit';
        }
        if (preg_match('/aufenthalt|visum|ausländer|einbürgerung|migration/i', $text)) {
            return 'regulation';
        }
        if (preg_match('/veranstaltung|fest|festival|markt|messe|karneval/i', $text)) {
            return 'event';
        }

        return 'general';
    }

    protected function determineRelevance(string $title, string $desc): string
    {
        $text = mb_strtolower($title.' '.$desc);

        if (preg_match('/ausländer|expat|international|migration|visum|aufenthalt/i', $text)) {
            return 'expat';
        }
        if (preg_match('/kvb|tram|bus|bahn|verkehr|sperrung|umleitung|baustelle/i', $text)) {
            return 'transit';
        }
        if (preg_match('/bürgeramt|finanzamt|standesamt|amt|behörde/i', $text)) {
            return 'bureaucracy';
        }
        if (preg_match('/köln|stadt|bürger|gesundheit/i', $text)) {
            return 'all';
        }

        return 'skip';
    }
}
