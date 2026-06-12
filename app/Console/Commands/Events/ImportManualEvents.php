<?php

namespace App\Console\Commands\Events;

use App\Models\Event;
use App\Models\User;
use App\Services\VenueResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

/**
 * The ManualSource: hand-curated events from a YAML catalogue, the
 * same philosophy as the bureaucracy content. Records are trusted —
 * verified_at is set, no AI pass needed — and recurring events are
 * single RRULE rows. Idempotent: upserts on (source=manual, uid).
 */
class ImportManualEvents extends Command
{
    protected $signature = 'events:import-manual';

    protected $description = 'Import the hand-curated events catalogue (database/seeders/data/events)';

    public function handle(VenueResolver $venues): int
    {
        $path = database_path('seeders/data/events/manual.yaml');
        if (! is_file($path)) {
            $this->error("Catalogue not found: {$path}");

            return self::FAILURE;
        }

        $records = Yaml::parseFile($path)['events'] ?? [];
        $organiser = User::query()->firstOrCreate(
            ['email' => 'system@expadu.com'],
            ['name' => 'Expadu', 'password' => bcrypt(str()->random(40))],
        );

        $imported = 0;
        foreach ($records as $record) {
            // INTERVAL>1 series anchor their phase on DTSTART's week, so
            // re-imports must NOT re-derive it — the run date would
            // silently shift which week an every-4-weeks event lands on.
            $existing = Event::query()
                ->where('source', 'manual')
                ->where('source_uid', $record['uid'])
                ->first();

            $startsAt = $existing && $existing->recurrence
                ? CarbonImmutable::parse($existing->starts_at)
                : $this->startsAt($record['schedule']);

            $endsAt = isset($record['schedule']['duration_min'])
                ? $startsAt->addMinutes((int) $record['schedule']['duration_min'])
                : null;

            $venue = $venues->resolve(
                $record['venue']['name'],
                $record['venue']['address'] ?? null,
                $record['venue']['lat'] ?? null,
                $record['venue']['lng'] ?? null,
            );

            $event = Event::query()->updateOrCreate(
                ['source' => 'manual', 'source_uid' => $record['uid']],
                [
                    'title' => $record['title'],
                    'title_en' => $record['title_en'],
                    'summary_en' => trim($record['summary_en']),
                    'tip_en' => isset($record['tip_en']) ? trim($record['tip_en']) : null,
                    'category' => $record['category'],
                    'language' => $record['language'],
                    'chips' => $record['chips'] ?? [],
                    'price_text' => $record['price_text'] ?? null,
                    'is_free' => (bool) ($record['is_free'] ?? false),
                    'relevance' => (float) ($record['relevance'] ?? 1.0),
                    'needs_review' => false,
                    'status' => 'active',
                    'verified_at' => now(),
                    'source_url' => $record['source_url'] ?? null,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'recurrence' => $record['schedule']['rrule'] ?? null,
                    'location_name' => $record['venue']['name'],
                    'address' => $record['venue']['address'] ?? null,
                    'venue_id' => $venue->id,
                    'organiser_id' => $organiser->id,
                    'quality_score' => 1.0,
                ],
            );

            // Set the PostGIS point for legacy consumers
            if (($record['venue']['lat'] ?? null) && ($record['venue']['lng'] ?? null)) {
                DB::statement(
                    'UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
                    [$record['venue']['lng'], $record['venue']['lat'], $event->id],
                );
            }

            // The old scraper seeded the same community events as one
            // row per week — hide those so the catalogue is canonical.
            // (NULL-safe: legacy rows have source = NULL.)
            Event::query()
                ->where('title', $record['title'])
                ->where('id', '!=', $event->id)
                ->whereRaw("source IS DISTINCT FROM 'manual'")
                ->update(['status' => 'hidden']);

            $imported++;
        }

        $this->info("Imported/updated {$imported} curated event(s).");

        return self::SUCCESS;
    }

    /**
     * Next occurrence of schedule.weekday at schedule.time — series
     * DTSTART for recurring records, the date itself for one-offs.
     * Re-imports keep series stable: the RRULE generates the same
     * weekly slots regardless of which week DTSTART lands in.
     *
     * @param  array{weekday: string, time: string}  $schedule
     */
    private function startsAt(array $schedule): CarbonImmutable
    {
        $weekdays = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
        $target = $weekdays[strtoupper($schedule['weekday'])] ?? 1;

        $day = CarbonImmutable::now('Europe/Berlin')->startOfDay();
        while ($day->isoWeekday() !== $target) {
            $day = $day->addDay();
        }

        [$hour, $minute] = array_map('intval', explode(':', $schedule['time']));
        $start = $day->setTime($hour, $minute);

        // Today's slot already passed → next week's
        return $start->isPast() ? $start->addWeek() : $start;
    }
}
