<?php

namespace App\Console\Commands;

use App\Jobs\ProcessEventJob;
use App\Models\Event;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ScrapeEvents extends Command
{
    protected $signature = 'events:scrape';

    protected $description = 'Scrape events from external sources and create Event records';

    public function handle(): int
    {
        $systemUser = User::where('email', 'system@expadu.com')->first();
        if (! $systemUser) {
            $this->error('System user (system@expadu.com) not found. Run database seeder first.');

            return self::FAILURE;
        }

        $created = 0;

        // Recurring community events live in the manual source now
        // (events:import-manual) as single RRULE records — the old
        // per-week duplicate templates are gone.
        $koelnCount = $this->scrapeKoelnDe($systemUser->id);
        $created += $koelnCount;
        $this->info("koeln.de events: {$koelnCount} created");

        $this->info("Total: {$created} events created/updated");

        return self::SUCCESS;
    }

    /**
     * Fetch events from koeln.de via their Tribe Events REST API.
     */
    protected function scrapeKoelnDe(int $organiserId): int
    {
        $created = 0;

        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Expadu/1.0 (Event Aggregator for Expats)'])
                ->get('https://www.koeln.de/wp-json/tribe/events/v1/events', [
                    'per_page' => 50,
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays(30)->toDateString(),
                ]);

            if (! $response->successful()) {
                Log::info('events:scrape — koeln.de API returned '.$response->status());

                return 0;
            }

            foreach ($response->json('events', []) as $ev) {
                $title = html_entity_decode(trim($ev['title'] ?? ''), ENT_QUOTES, 'UTF-8');
                if (! $title) {
                    continue;
                }

                $startsAt = Carbon::parse($ev['start_date'] ?? now());
                if ($startsAt->isPast()) {
                    continue;
                }

                // Dedupe on the source's own id when it has one; fall
                // back to title+date for sources without stable ids.
                $sourceUid = isset($ev['id']) ? (string) $ev['id'] : null;
                if ($sourceUid && Event::where('source', 'koeln.de')->where('source_uid', $sourceUid)->exists()) {
                    continue;
                }

                if (Event::where('title', $title)->whereDate('starts_at', $startsAt->toDateString())->exists()) {
                    continue;
                }

                $desc = mb_substr(strip_tags($ev['description'] ?? ''), 0, 1000);
                $venue = $ev['venue']['venue'] ?? null;
                $address = $ev['venue']['address'] ?? null;
                $sourceUrl = $ev['url'] ?? null;

                // Prefer source categories over keyword guessing
                $sourceCategories = array_map(
                    fn ($c) => $c['name'] ?? '',
                    $ev['categories'] ?? [],
                );
                $category = $this->mapSourceCategory($sourceCategories)
                    ?? $this->categoriseEvent($title, $desc);

                // Store source category names as tags for future LLM enrichment context
                $sourceTags = array_values(array_filter($sourceCategories));

                [$isFree, $price, $priceText] = $this->parseCost($ev['cost'] ?? null);

                $qualityScore = $this->computeInitialQuality([
                    'venue' => $venue,
                    'address' => $address,
                    'description' => $desc,
                    'starts_at' => $startsAt,
                    'source_url' => $sourceUrl,
                    'price_known' => $isFree !== null,
                ]);

                $event = Event::create([
                    'title' => mb_substr($title, 0, 255),
                    'emoji' => $this->categoryEmoji($category),
                    'category' => $category,
                    'description' => $desc ?: null,
                    'starts_at' => $startsAt,
                    'ends_at' => isset($ev['end_date']) ? Carbon::parse($ev['end_date']) : $startsAt->copy()->addHours(2),
                    'location_name' => $venue ? html_entity_decode($venue, ENT_QUOTES, 'UTF-8') : null,
                    'address' => $address ? html_entity_decode($address, ENT_QUOTES, 'UTF-8') : null,
                    'is_free' => $isFree ?? false,
                    'price' => $price,
                    'price_text' => $priceText,
                    'source' => 'koeln.de',
                    'source_url' => $sourceUrl,
                    'source_uid' => $sourceUid,
                    'tags' => $sourceTags ?: null,
                    'organiser_id' => $organiserId,
                    'quality_score' => $qualityScore,
                ]);

                ProcessEventJob::dispatch($event);

                $created++;
            }
        } catch (\Exception $e) {
            Log::warning('events:scrape — koeln.de API error: '.$e->getMessage());
        }

        return $created;
    }

    /**
     * Parse a raw cost string into (is_free, price, price_text).
     *
     * Returns:
     *   - is_free: true = confirmed free, false = has cost, null = unknown
     *   - price: lowest numeric price found, or null
     *   - price_text: cleaned display string, or null when empty
     *
     * @return array{bool|null, float|null, string|null}
     */
    protected function parseCost(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            // Empty = unknown, NOT free
            return [null, null, null];
        }

        $cleaned = trim(html_entity_decode($raw, ENT_QUOTES, 'UTF-8'));

        // Explicitly free by keyword
        if (preg_match('/\b(frei|free|kostenlos|gratis|eintritt frei)\b/i', $cleaned)) {
            return [true, null, 'Free'];
        }

        // Extract all numeric prices (handles "8–12 €", "15,00€", "ab 9.50 €")
        preg_match_all('/(\d+)[,.]?(\d*)/', $cleaned, $matches);
        $prices = [];
        foreach ($matches[1] as $i => $whole) {
            $decimal = $matches[2][$i] ? '.'.$matches[2][$i] : '';
            $prices[] = (float) ($whole.$decimal);
        }

        $minPrice = ! empty($prices) ? min($prices) : null;

        // "0 €", "0" — numeric zero means free
        if ($minPrice !== null && $minPrice == 0 && mb_strlen(trim(preg_replace('/[\d\s,.]/', '', $cleaned))) <= 2) {
            return [true, null, 'Free'];
        }

        // Normalise display text: strip excess whitespace, cap length
        $displayText = mb_substr(preg_replace('/\s+/', ' ', $cleaned), 0, 60);

        return [false, $minPrice, $displayText];
    }

    /**
     * Compute an initial quality score 0.0–1.0 at scrape time.
     *
     * The enrichment command may later refine this.
     *
     * @param  array{venue: ?string, address: ?string, description: ?string, starts_at: Carbon, source_url: ?string, price_known: bool}  $fields
     */
    protected function computeInitialQuality(array $fields): float
    {
        $score = 0.0;

        if ($fields['venue'] && ! in_array(mb_strtolower($fields['venue']), ['cologne', 'köln', ''])) {
            $score += 0.25;
        }
        if ($fields['address'] && ! in_array(mb_strtolower($fields['address']), ['cologne', 'köln', ''])) {
            $score += 0.2;
        }
        if ($fields['description'] && mb_strlen($fields['description']) > 20) {
            $score += 0.2;
        }
        if ($fields['starts_at']->hour > 0) {
            $score += 0.15;
        }
        if ($fields['source_url']) {
            $score += 0.1;
        }
        if ($fields['price_known']) {
            $score += 0.1;
        }

        return min(1.0, $score);
    }

    /**
     * Map koeln.de source category names to our internal category system.
     * Returns null when no confident match — falls back to keyword guessing.
     *
     * @param  string[]  $sourceCategories
     */
    protected function mapSourceCategory(array $sourceCategories): ?string
    {
        $map = [
            'Konzerte' => 'music',
            'Partys & Clubbing' => 'music',
            'Festivals' => 'music',
            'Jazz' => 'music',
            'Theater' => 'culture',
            'Ausstellungen' => 'culture',
            'Kino' => 'culture',
            'Lesungen' => 'culture',
            'Kabarett & Comedy' => 'culture',
            'Führungen' => 'culture',
            'Vorträge' => 'culture',
            'Stadtführungen' => 'culture',
            'Märkte' => 'food',
            'Flohmärkte' => 'food',
            'Sport' => 'sports',
            'Yoga' => 'sports',
            'Laufen' => 'sports',
            'Sprachen' => 'language',
            'Networking' => 'social',
            'Stammtisch' => 'social',
        ];

        foreach ($sourceCategories as $name) {
            if (isset($map[$name])) {
                return $map[$name];
            }
        }

        return null;
    }

    protected function categoriseEvent(string $title, string $desc): string
    {
        $text = mb_strtolower($title.' '.$desc);

        if (preg_match('/sprach|language|deutsch|german|tandem/i', $text)) {
            return 'language';
        }
        if (preg_match('/musik|music|jazz|konzert|concert|live|dj/i', $text)) {
            return 'music';
        }
        if (preg_match('/sport|lauf|run|yoga|fitness|schwimm/i', $text)) {
            return 'sports';
        }
        if (preg_match('/food|essen|kulinar|street food|markt|market|brau|beer/i', $text)) {
            return 'food';
        }
        if (preg_match('/kunst|art|museum|galerie|gallery|theater|theatre|kino|film|ausstellung|exhibit/i', $text)) {
            return 'culture';
        }
        if (preg_match('/expat|international|community|meetup|stammtisch|networking/i', $text)) {
            return 'social';
        }

        return 'culture';
    }

    protected function categoryEmoji(string $category): string
    {
        return match ($category) {
            'language' => '🗣️',
            'music' => '🎵',
            'sports' => '🏃',
            'food' => '🍽️',
            'culture' => '🎭',
            'social' => '🌍',
            default => '📅',
        };
    }
}
