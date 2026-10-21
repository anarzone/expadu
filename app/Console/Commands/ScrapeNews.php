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

        $aktuellesCount = $this->scrapeKvbAktuelles();
        $created += $aktuellesCount;
        $this->info("KVB aktuelles: {$aktuellesCount}");

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
     * KVB's live service page. The page is the whole truth: a notice it
     * still lists stays open however long the works run, and one it no
     * longer lists has ended. Each notice also carries a schema.org
     * SpecialAnnouncement, read in preference to the markup.
     */
    protected function scrapeKvbBetriebslage(): int
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Expadu/1.0)'])
                ->get('https://www.kvb.koeln/fahrtinfo/betriebslage/index.html');

            if (! $response->successful()) {
                Log::info('news:scrape — KVB betriebslage returned '.$response->status());

                return 0;
            }

            $notices = $this->parseBetriebslage($this->decodeKvbPage($response->body()));
            if ($notices === null) {
                // Nothing recognisable: leave the open notices as they are
                // rather than closing them all on a layout change.
                Log::warning('news:scrape — KVB betriebslage layout not recognised');

                return 0;
            }

            return $this->reconcileBetriebslage($notices);
        } catch (\Exception $e) {
            Log::warning('news:scrape — KVB betriebslage error: '.$e->getMessage());

            return 0;
        }
    }

    /**
     * KVB serves Windows-1252. Read as ISO-8859-1, the en dash between stop
     * names (0x96) became an invisible control character.
     */
    protected function decodeKvbPage(string $body): string
    {
        return mb_check_encoding($body, 'UTF-8') ? $body : mb_convert_encoding($body, 'UTF-8', 'Windows-1252');
    }

    /**
     * Null when the page isn't the service page at all.
     *
     * @return list<array{line: string, is_bus: bool, title: string, details: string}>|null
     */
    protected function parseBetriebslage(string $html): ?array
    {
        $text = fn (string $value): string => trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));

        $notices = [];
        preg_match_all('/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/si', $html, $blocks);
        foreach ($blocks[1] as $json) {
            $data = json_decode($json, true);
            if (! is_array($data) || ($data['@type'] ?? null) !== 'SpecialAnnouncement') {
                continue;
            }

            $properties = collect($data['additionalProperty'] ?? []);
            $line = trim((string) ($properties->firstWhere('name', 'Linie')['value'] ?? ''));
            $name = $text((string) ($data['name'] ?? ''));
            $title = trim((string) preg_replace('/^Linie\s+'.preg_quote($line, '/').'\s*:\s*/u', '', $name));
            if ($line === '' || mb_strlen($line) > 10 || mb_strlen($title) < 5) {
                continue;
            }

            $notices[] = [
                'line' => $line,
                'is_bus' => ($data['serviceType'] ?? $properties->firstWhere('name', 'serviceType')['value'] ?? null) === 'Bus',
                'title' => $title,
                'details' => $text((string) ($data['description'] ?? '')),
            ];
        }

        if ($notices !== []) {
            return $notices;
        }

        // No structured data: fall back to the markup, but only on the real page.
        if (! str_contains($html, 'id="stoer_bahn"')) {
            return null;
        }

        preg_match_all('/<li\s+class="list-group-item\s+linie(\w+)\s+(bahn|bus)"[^>]*>(.*?)<\/li>/si', $html, $entries, PREG_SET_ORDER);
        foreach ($entries as [, $line, $kind, $content]) {
            $title = preg_match('/<b[^>]*>(.*?)<\/b>/si', $content, $m) ? $text($m[1]) : '';
            if (mb_strlen($line) > 10 || mb_strlen($title) < 5) {
                continue;
            }

            $notices[] = [
                'line' => $line,
                'is_bus' => $kind === 'bus',
                'title' => $title,
                'details' => preg_match('/<div\s+class="weitereinfos[^"]*"[^>]*>(.*?)<\/div>/si', $content, $m) ? $text($m[1]) : '',
            ];
        }

        return $notices;
    }

    /**
     * Brings city_news in line with what KVB lists now. Returns how many
     * notices are new.
     *
     * @param  list<array{line: string, is_bus: bool, title: string, details: string}>  $notices
     */
    protected function reconcileBetriebslage(array $notices): int
    {
        $now = now();
        // Open rows outlive a failed scrape for a while, then lapse on their
        // own instead of showing an old notice indefinitely.
        $openUntil = $now->copy()->addHours(2);

        $open = CityNews::query()
            ->where('source', 'kvb-betriebslage')
            ->where('expires_at', '>', $now)
            ->get();
        $kept = [];
        $unmatched = [];

        foreach ($notices as $notice) {
            $url = $this->betriebslageUrl($notice);
            $row = $open->firstWhere('source_url', $url) ?? CityNews::query()->where('source_url', $url)->latest('id')->first();
            if ($row === null) {
                $unmatched[] = $notice;

                continue;
            }

            $row->update(['summary' => $notice['details'] ?: $row->summary, 'published_at' => $now, 'expires_at' => $openUntil]);
            $kept[$row->id] = true;
        }

        $gone = $open->reject(fn (CityNews $row) => isset($kept[$row->id]));
        $created = 0;

        foreach ($unmatched as $notice) {
            // An edited notice (KVB moved its end time, say) keeps its row and
            // id, so it isn't announced again as a new disruption.
            $edited = $gone
                ->filter(fn (CityNews $row) => array_map('strval', $row->affected_lines ?? []) === [$notice['line']])
                ->map(fn (CityNews $row) => ['row' => $row, 'score' => $this->noticeSimilarity($row, $notice)])
                ->sortByDesc('score')
                ->first();

            $attributes = [
                'title' => mb_substr(($notice['is_bus'] ? 'Bus ' : 'Linie ').$notice['line'].': '.$notice['title'], 0, 255),
                'summary' => $notice['details'] ?: null,
                'source_url' => $this->betriebslageUrl($notice),
                'severity' => $this->inferSeverity($notice['title'].' '.$notice['details']),
                'published_at' => $now,
                'expires_at' => $openUntil,
            ];

            if ($edited !== null && $edited['score'] >= 0.6) {
                $edited['row']->update($attributes);
                $gone = $gone->reject(fn (CityNews $row) => $row->is($edited['row']));

                continue;
            }

            CityNews::create($attributes + [
                'source' => 'kvb-betriebslage',
                'category' => 'transit',
                'relevance' => 'transit',
                'affected_lines' => [$notice['line']],
            ]);
            $created++;
        }

        if ($gone->isNotEmpty()) {
            CityNews::query()->whereKey($gone->modelKeys())->update(['expires_at' => $now]);
        }

        return $created;
    }

    /** @param  array{line: string, title: string}  $notice */
    protected function betriebslageUrl(array $notice): string
    {
        return 'https://www.kvb.koeln/fahrtinfo/betriebslage/#'.md5($notice['line'].'_'.$notice['title']);
    }

    /** @param  array{line: string, is_bus: bool, title: string, details: string}  $notice */
    protected function noticeSimilarity(CityNews $row, array $notice): float
    {
        similar_text(
            mb_strtolower(preg_replace('/^(Linie|Bus)\s+\S+:\s*/u', '', $row->title).' '.$row->summary),
            mb_strtolower($notice['title'].' '.$notice['details']),
            $percent,
        );

        return $percent / 100;
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

            $html = $this->decodeKvbPage($response->body());

            // Extract headings which contain disruption info
            preg_match_all('/<h[234][^>]*>(.*?)<\/h[234]>/si', $html, $headings);

            // Open KVB news, so a reworded heading updates its notice instead of adding a twin.
            $open = CityNews::query()->where('source', 'kvb')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->get();

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

                // KVB rewords headings ("Linien 1, 7, 9" → "Linien 1, 7 und 9"): the
                // same notice, so its row takes the new wording.
                $twin = $open->first(fn (CityNews $row): bool => self::aktuellesKey((string) $row->title) === self::aktuellesKey($title));
                if ($twin !== null) {
                    $twin->update([
                        'title' => mb_substr($title, 0, 255),
                        'summary' => $this->buildSummary($title, $lines),
                        'affected_lines' => $lines ?: null,
                        'severity' => $severity,
                        'source_url' => $url,
                    ]);

                    continue;
                }

                $open->push(CityNews::create([
                    'title' => mb_substr($title, 0, 255),
                    'summary' => $this->buildSummary($title, $lines),
                    'source' => 'kvb',
                    'source_url' => $url,
                    'category' => 'transit',
                    'relevance' => 'transit',
                    'affected_lines' => $lines ?: null,
                    'severity' => $severity,
                    // When the works start, never a date still ahead: a future
                    // published_at sorted the notice first and showed it early.
                    'published_at' => $dateRange['start']?->isPast() ? $dateRange['start'] : now(),
                    'expires_at' => $dateRange['end'],
                ]));

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
     * A KVB news heading's identity, whatever separators it lists lines with:
     * "Linien 1, 7, 9", "Linien 1, 7 und 9" and "Linien 1 + 7 + 9" are one notice.
     */
    public static function aktuellesKey(string $title): string
    {
        $key = mb_strtolower($title);
        $key = preg_replace('/\b(und|and)\b|[,+&]/u', ' ', $key);

        return trim((string) preg_replace('/\s+/u', ' ', (string) $key));
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
        // Pattern: "Bis DD.MM.YYYY" — only the end is known
        elseif (preg_match('/\bbis\s+(?:zum\s+)?(\d{1,2})\.(\d{1,2})\.(\d{4})/i', $title, $m)) {
            try {
                $result['end'] = Carbon::createFromFormat('d.m.Y', "{$m[1]}.{$m[2]}.{$m[3]}")->endOfDay();
            } catch (\Exception) {
                // ignore
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
