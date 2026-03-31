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

        // 1. Recurring community events
        $created += $this->createRecurringEvents($systemUser->id);
        $this->info("Recurring events: {$created} created");

        // 2. Scrape koeln.de event calendar (RSS feed)
        $koelnCount = $this->scrapeKoelnDe($systemUser->id);
        $created += $koelnCount;
        $this->info("koeln.de events: {$koelnCount} created");

        // 3. Scrape Stadt Köln Veranstaltungen
        $stadtCount = $this->scrapeStadtKoeln($systemUser->id);
        $created += $stadtCount;
        $this->info("stadt-koeln.de events: {$stadtCount} created");

        $this->info("Total: {$created} events created/updated");

        return self::SUCCESS;
    }

    /**
     * Fetch events from koeln.de via their Tribe Events REST API.
     * Endpoint: https://www.koeln.de/wp-json/tribe/events/v1/events
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

            $data = $response->json();

            foreach ($data['events'] ?? [] as $ev) {
                $title = html_entity_decode(trim($ev['title'] ?? ''), ENT_QUOTES, 'UTF-8');
                if (! $title) {
                    continue;
                }

                $startsAt = Carbon::parse($ev['start_date'] ?? now());
                if ($startsAt->isPast()) {
                    continue;
                }

                // Deduplicate by title + date
                if (Event::where('title', $title)->whereDate('starts_at', $startsAt->toDateString())->exists()) {
                    continue;
                }

                $desc = mb_substr(strip_tags($ev['description'] ?? ''), 0, 1000);
                $venue = $ev['venue']['venue'] ?? null;
                $address = $ev['venue']['address'] ?? null;
                $category = $this->categoriseEvent($title, $desc);
                $isFree = str_contains(mb_strtolower($ev['cost'] ?? ''), 'frei')
                    || str_contains(mb_strtolower($ev['cost'] ?? ''), 'free')
                    || empty($ev['cost']);

                Event::create([
                    'title' => mb_substr($title, 0, 255),
                    'emoji' => $this->categoryEmoji($category),
                    'category' => $category,
                    'description' => $desc ?: null,
                    'starts_at' => $startsAt,
                    'ends_at' => isset($ev['end_date']) ? Carbon::parse($ev['end_date']) : $startsAt->copy()->addHours(2),
                    'location_name' => $venue ? html_entity_decode($venue, ENT_QUOTES, 'UTF-8') : 'Cologne',
                    'address' => $address ? html_entity_decode($address, ENT_QUOTES, 'UTF-8') : 'Cologne',
                    'is_free' => $isFree,
                    'organiser_id' => $organiserId,
                ]);

                $created++;
            }
        } catch (\Exception $e) {
            Log::warning('events:scrape — koeln.de API error: '.$e->getMessage());
        }

        return $created;
    }

    /**
     * Scrape from Stadt Köln official events.
     */
    protected function scrapeStadtKoeln(int $organiserId): int
    {
        $created = 0;

        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Expadu/1.0'])
                ->get('https://www.stadt-koeln.de/leben-in-koeln/freizeit-natur-sport/veranstaltungskalender/');

            if (! $response->successful()) {
                return 0;
            }

            $html = $response->body();

            // Extract event data from structured HTML
            preg_match_all('/<(?:h[234]|a|span)[^>]*>([^<]{10,100})<\/(?:h[234]|a|span)>/i', $html, $matches);

            $seen = [];
            foreach ($matches[1] ?? [] as $rawTitle) {
                $title = trim(strip_tags($rawTitle));
                if (! $title || mb_strlen($title) < 8 || mb_strlen($title) > 120) {
                    continue;
                }

                // Skip generic navigation/UI text
                if (preg_match('/^(Menü|Suche|Kontakt|Impressum|Datenschutz|Navigation|Anmelden|Startseite)/i', $title)) {
                    continue;
                }

                $key = mb_strtolower($title);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                if (Event::where('title', $title)->whereDate('starts_at', '>=', today())->exists()) {
                    continue;
                }

                $category = $this->categoriseEvent($title, '');

                // Try to parse a date from the title (e.g. "12.04.2026: Frühlingsfest")
                $startsAt = null;
                if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $title, $dm)) {
                    try {
                        $startsAt = Carbon::createFromFormat('d.m.Y', "{$dm[1]}.{$dm[2]}.{$dm[3]}")->setTime(10, 0);
                        if ($startsAt->isPast()) {
                            $startsAt = null;
                        }
                    } catch (\Exception) {
                        $startsAt = null;
                    }
                }

                // Skip events where we can't determine a real date — no random dates
                if (! $startsAt) {
                    continue;
                }

                Event::create([
                    'title' => mb_substr($title, 0, 255),
                    'emoji' => $this->categoryEmoji($category),
                    'category' => $category,
                    'description' => 'Official city event — Stadt Köln',
                    'starts_at' => $startsAt,
                    'ends_at' => null,
                    'location_name' => null,
                    'address' => null,
                    'is_free' => true,
                    'organiser_id' => $organiserId,
                    'source' => 'stadt-koeln',
                    'quality_score' => 0.2,
                ]);

                $created++;

                if ($created >= 10) {
                    break;
                }
            }
        } catch (\Exception $e) {
            Log::warning('events:scrape — stadt-koeln.de error: '.$e->getMessage());
        }

        return $created;
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

        return 'culture'; // default
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
                    'max_attendees' => $t['max_attendees'],
                    'organiser_id' => $organiserId,
                ]);

                $created++;
            }
        }

        return $created;
    }
}
