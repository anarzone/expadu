<?php

namespace App\Console\Commands;

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

        $recurringCount = $this->createRecurringEvents($systemUser->id);
        $created += $recurringCount;
        $this->info("Recurring events: {$recurringCount} created");

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

                if (Event::where('title', $title)->whereDate('starts_at', $startsAt->toDateString())->exists()) {
                    continue;
                }

                $desc = mb_substr(strip_tags($ev['description'] ?? ''), 0, 1000);
                $venue = $ev['venue']['venue'] ?? null;
                $address = $ev['venue']['address'] ?? null;
                $category = $this->categoriseEvent($title, $desc);
                $sourceUrl = $ev['url'] ?? null;

                [$isFree, $price, $priceText] = $this->parseCost($ev['cost'] ?? null);

                $qualityScore = $this->computeInitialQuality([
                    'venue' => $venue,
                    'address' => $address,
                    'description' => $desc,
                    'starts_at' => $startsAt,
                    'source_url' => $sourceUrl,
                    'price_known' => $isFree !== null,
                ]);

                Event::create([
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
                    'organiser_id' => $organiserId,
                    'quality_score' => $qualityScore,
                ]);

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

    /**
     * Create known recurring expat community events for the next 4 weeks.
     */
    protected function createRecurringEvents(int $organiserId): int
    {
        $templates = [
            ['title' => 'Language Exchange Night', 'emoji' => '🗣️', 'category' => 'language', 'description' => 'Weekly language exchange at Café Schmitz. All levels welcome.', 'location_name' => 'Café Schmitz', 'address' => 'Venloer Str. 236, Ehrenfeld', 'day_of_week' => 3, 'hour' => 19, 'duration_hours' => 2, 'is_free' => true, 'max_attendees' => 30],
            ['title' => 'Expat Stammtisch Cologne', 'emoji' => '🍺', 'category' => 'social', 'description' => 'Monthly gathering for internationals. Meet new people, share tips, drink Kölsch.', 'location_name' => 'Brauhaus Früh', 'address' => 'Am Hof 12-18, Altstadt', 'day_of_week' => 5, 'hour' => 19, 'duration_hours' => 3, 'is_free' => true, 'max_attendees' => 50],
            ['title' => 'Morning Yoga in Volksgarten', 'emoji' => '🧘', 'category' => 'sports', 'description' => 'Free outdoor yoga every Saturday morning. Bring your own mat.', 'location_name' => 'Volksgarten', 'address' => 'Volksgartenstr., Südstadt', 'day_of_week' => 6, 'hour' => 8, 'duration_hours' => 1, 'is_free' => true, 'max_attendees' => 25],
            ['title' => 'Expat Running Club — Rheinufer', 'emoji' => '🏃', 'category' => 'sports', 'description' => 'Weekly Saturday run along the Rhine. All paces welcome.', 'location_name' => 'Rheinufer, Deutz Bridge', 'address' => 'Deutzer Brücke, Deutz', 'day_of_week' => 6, 'hour' => 9, 'duration_hours' => 1, 'is_free' => true, 'max_attendees' => 40],
            ['title' => 'German Practice Group', 'emoji' => '📚', 'category' => 'language', 'description' => 'Practice conversational German. A1-B2 levels. Native speakers help.', 'location_name' => 'StadtBibliothek Köln', 'address' => 'Josef-Haubrich-Hof 1, Neustadt-Süd', 'day_of_week' => 1, 'hour' => 18, 'duration_hours' => 1.5, 'is_free' => true, 'max_attendees' => 15],
        ];

        $created = 0;

        foreach ($templates as $t) {
            for ($week = 0; $week < 4; $week++) {
                $date = now()->copy()->next((int) $t['day_of_week'])->addWeeks($week);
                $startsAt = $date->copy()->setTime($t['hour'], 0);

                if (Event::where('title', $t['title'])->whereDate('starts_at', $startsAt->toDateString())->exists()) {
                    continue;
                }

                Event::create([
                    'title' => $t['title'],
                    'emoji' => $t['emoji'],
                    'category' => $t['category'],
                    'description' => $t['description'],
                    'starts_at' => $startsAt,
                    'ends_at' => $startsAt->copy()->addHours($t['duration_hours']),
                    'location_name' => $t['location_name'],
                    'address' => $t['address'],
                    'is_free' => $t['is_free'],
                    'price_text' => 'Free',
                    'max_attendees' => $t['max_attendees'],
                    'organiser_id' => $organiserId,
                    'quality_score' => 0.7,
                ]);

                $created++;
            }
        }

        return $created;
    }
}
