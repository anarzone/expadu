<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\User;
use Illuminate\Console\Command;

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

        $this->info('Scraping events for Cologne...');

        // Generate recurring expat community events
        $created = 0;
        $created += $this->createRecurringEvents($systemUser->id);

        // TODO: Scrape from koeln.de events calendar
        // TODO: Scrape from meetup.com Cologne expat groups
        // TODO: Scrape from eventbrite Cologne

        $this->info("Created {$created} events.");

        return self::SUCCESS;
    }

    /**
     * Create known recurring expat community events for the next 4 weeks.
     */
    protected function createRecurringEvents(int $organiserId): int
    {
        $templates = [
            [
                'title' => 'Language Exchange Night',
                'emoji' => '🗣️',
                'category' => 'language',
                'description' => 'Weekly language exchange at Café Schmitz. All levels welcome — split into pairs by language. Bring curiosity and a coffee order.',
                'location_name' => 'Café Schmitz',
                'address' => 'Venloer Str. 236, Ehrenfeld',
                'day_of_week' => 3, // Wednesday
                'hour' => 19,
                'duration_hours' => 2,
                'is_free' => true,
                'max_attendees' => 30,
            ],
            [
                'title' => 'Expat Stammtisch Cologne',
                'emoji' => '🍺',
                'category' => 'social',
                'description' => 'Monthly gathering for internationals. Meet new people, share tips about life in Cologne, drink Kölsch.',
                'location_name' => 'Brauhaus Früh',
                'address' => 'Am Hof 12-18, Altstadt',
                'day_of_week' => 5, // Friday (first Friday of month)
                'hour' => 19,
                'duration_hours' => 3,
                'is_free' => true,
                'max_attendees' => 50,
            ],
            [
                'title' => 'Morning Yoga in Volksgarten',
                'emoji' => '🧘',
                'category' => 'sports',
                'description' => 'Free outdoor yoga every Saturday morning. Bring your own mat. All levels welcome.',
                'location_name' => 'Volksgarten',
                'address' => 'Volksgartenstr., Südstadt',
                'day_of_week' => 6, // Saturday
                'hour' => 8,
                'duration_hours' => 1,
                'is_free' => true,
                'max_attendees' => 25,
            ],
            [
                'title' => 'Expat Running Club — Rheinufer',
                'emoji' => '🏃',
                'category' => 'sports',
                'description' => 'Weekly Saturday run along the Rhine. All paces welcome — 5K or 10K. Coffee after.',
                'location_name' => 'Rheinufer, Deutz Bridge',
                'address' => 'Deutzer Brücke, Deutz',
                'day_of_week' => 6, // Saturday
                'hour' => 9,
                'duration_hours' => 1,
                'is_free' => true,
                'max_attendees' => 40,
            ],
            [
                'title' => 'German Practice Group',
                'emoji' => '📚',
                'category' => 'language',
                'description' => 'Practice conversational German in a supportive group. A1-B2 levels. Native speakers help.',
                'location_name' => 'StadtBibliothek Köln',
                'address' => 'Josef-Haubrich-Hof 1, Neustadt-Süd',
                'day_of_week' => 1, // Monday
                'hour' => 18,
                'duration_hours' => 1.5,
                'is_free' => true,
                'max_attendees' => 15,
            ],
        ];

        $created = 0;
        $now = now();

        foreach ($templates as $template) {
            // Create events for the next 4 weeks
            for ($week = 0; $week < 4; $week++) {
                $date = $now->copy()->next((int) $template['day_of_week'])->addWeeks($week);
                $startsAt = $date->copy()->setTime($template['hour'], 0);
                $endsAt = $startsAt->copy()->addHours($template['duration_hours']);

                // Skip if already exists
                $exists = Event::where('title', $template['title'])
                    ->whereDate('starts_at', $startsAt->toDateString())
                    ->exists();

                if ($exists) {
                    continue;
                }

                Event::create([
                    'title' => $template['title'],
                    'emoji' => $template['emoji'],
                    'category' => $template['category'],
                    'description' => $template['description'],
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'location_name' => $template['location_name'],
                    'address' => $template['address'],
                    'is_free' => $template['is_free'],
                    'max_attendees' => $template['max_attendees'],
                    'organiser_id' => $organiserId,
                ]);

                $created++;
            }
        }

        return $created;
    }
}
