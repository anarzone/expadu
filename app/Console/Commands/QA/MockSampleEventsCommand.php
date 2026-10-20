<?php

namespace App\Console\Commands\QA;

use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Local only: the Events mock's sample day (Wednesday 30 September 2026) as
 * listings and weather, so tools/mock-compare shows the app and the mock with
 * the same words. The two events are the mock's own fictional ones
 * (prototype/dev/design/connected/sample-day.js) and the hours its sample
 * weather (events-next/sample-weather.js), merged into this machine's
 * forecast cache for that day only. Pair it with mock:sample-day and the
 * `mock_clock` cookie. `--remove` takes them out again.
 */
class MockSampleEventsCommand extends Command
{
    protected $signature = 'mock:sample-events
        {--remove : Take the sample events and weather out}
        {--email= : The account that lists them (default: the E2E account)}';

    protected $description = 'Local only: the Events mock\'s sample events and weather (30 Sept 2026), for tools/mock-compare';

    private const DATE = '2026-09-30';

    private const SOURCE = 'Sample';

    private const VENUE_MARK = 'Sample venue (mock:sample-events)';

    // Cologne, as WeatherService::getDayForecast reads it by default.
    private const WEATHER_KEYS = ['weather_fresh:50.9375_6.9603', 'weather_stale:50.9375_6.9603'];

    /** The mock's events: [uid, title, venue, area, lat, lng, start, end, description]. */
    private const EVENTS = [
        ['today-review-culture', 'Street culture night', 'Rheinauhafen', 'Neustadt-Süd', 50.92262, 6.96838, '18:30', '21:00', 'An illustrative evening of music by the Rhine. Sample event for this design preview.'],
        ['today-review-cinema', 'Cinema under the stars', 'Stadtgarten', 'Neustadt-Nord', 50.9445, 6.93694, '20:00', '22:00', 'An illustrative outdoor film night. Sample event for this design preview.'],
    ];

    /** The mock's hours: [sky, °C, chance of rain %], 00:00 to 23:00. */
    private const WEATHER = [
        ['clear', 11, 0], ['clear', 11, 0], ['clear', 10, 0], ['clear', 10, 0],
        ['mist', 9, 0], ['mist', 9, 0], ['mist', 10, 0], ['partly', 11, 0],
        ['partly', 13, 0], ['partly', 15, 0], ['clear', 17, 0], ['clear', 19, 0],
        ['clear', 20, 0], ['clear', 21, 0], ['clear', 21, 0], ['partly', 21, 0],
        ['partly', 20, 10], ['cloudy', 19, 30], ['showers', 17, 60], ['showers', 16, 70],
        ['rain', 15, 70], ['cloudy', 14, 40], ['partly', 13, 10], ['clear', 12, 0],
    ];

    /** What each sky is in the forecast's own terms (the street reads these back). */
    private const SKY = [
        'clear' => ['precipitation' => 0.0, 'cloud_cover' => 10, 'visibility' => 20000],
        'partly' => ['precipitation' => 0.0, 'cloud_cover' => 50, 'visibility' => 20000],
        'cloudy' => ['precipitation' => 0.0, 'cloud_cover' => 90, 'visibility' => 20000],
        'showers' => ['precipitation' => 0.5, 'cloud_cover' => 85, 'visibility' => 15000],
        'rain' => ['precipitation' => 2.0, 'cloud_cover' => 95, 'visibility' => 8000],
        'mist' => ['precipitation' => 0.0, 'cloud_cover' => 40, 'visibility' => 500],
    ];

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Refusing: the sample events are for local mock comparisons only.');

            return self::FAILURE;
        }

        $uids = array_map(fn (array $e) => "mock:{$e[0]}", self::EVENTS);

        if ($this->option('remove')) {
            Event::query()->whereIn('source_uid', $uids)->delete();
            Venue::query()->where('address_text', self::VENUE_MARK)->whereNotExists(
                fn ($q) => $q->from('events')->whereColumn('events.venue_id', 'venues.id'),
            )->delete();
            $this->weather(remove: true);
            $this->info('The sample events and weather are out.');

            return self::SUCCESS;
        }

        $email = (string) ($this->option('email') ?: env('E2E_EMAIL', 'e2e@expadu.test'));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No account {$email}. Run the E2ETestUserSeeder first.");

            return self::FAILURE;
        }

        foreach (self::EVENTS as [$uid, $title, $venueName, $area, $lat, $lng, $start, $end, $description]) {
            // Its own venue row, never a real one of the same name.
            $venue = Venue::query()->updateOrCreate(
                ['name' => $venueName, 'address_text' => self::VENUE_MARK],
                ['veedel' => $area, 'lat' => $lat, 'lng' => $lng],
            );
            Event::query()->updateOrCreate(['source_uid' => "mock:{$uid}"], [
                'title' => $title,
                'category' => 'culture',
                'description' => $description,
                'description_en' => $description,
                'summary_en' => $description,
                'source_lang' => 'en',
                'starts_at' => CarbonImmutable::parse(self::DATE." {$start}", 'Europe/Berlin'),
                'ends_at' => CarbonImmutable::parse(self::DATE." {$end}", 'Europe/Berlin'),
                'location_name' => $venueName,
                'venue_id' => $venue->id,
                'is_free' => false,
                'price_text' => null,
                'language' => null,
                'organiser_id' => $user->id,
                'source' => self::SOURCE,
                'source_url' => null,
                'status' => 'active',
                'is_curated' => true,
                'quality_score' => 1,
                'relevance' => 1,
            ]);
        }

        $this->weather(remove: false);
        $this->info('The mock\'s two sample events and its sample weather are in for '.self::DATE.'.');

        return self::SUCCESS;
    }

    /** Merges (or takes out) the sample day's hours in the cached forecast. */
    private function weather(bool $remove): void
    {
        $hours = array_map(function (array $hour, int $h): array {
            [$sky, $temperature, $chance] = $hour;

            return [
                'hour' => $h,
                'time' => CarbonImmutable::parse(sprintf('%s %02d:00', self::DATE, $h), 'Europe/Berlin')->toIso8601String(),
                'temperature' => $temperature,
                'precipitation_probability' => $chance,
                ...self::SKY[$sky],
                'icon' => null,
            ];
        }, self::WEATHER, array_keys(self::WEATHER));

        $wrote = false;

        foreach (self::WEATHER_KEYS as $key) {
            $data = Cache::get($key);

            if (! is_array($data) || $data === []) {
                continue;
            }

            $data['hourly'] = [
                ...array_values(array_filter($data['hourly'] ?? [], fn (array $entry) => ! str_starts_with((string) ($entry['time'] ?? ''), self::DATE))),
                ...($remove ? [] : $hours),
            ];
            // The fresh copy keeps a short life, so real refreshes come back.
            str_starts_with($key, 'weather_fresh:')
                ? Cache::put($key, $data, now()->addHours(2))
                : Cache::forever($key, $data);
            $wrote = true;
        }

        // Nothing cached yet: the stale copy is enough (the page reads it).
        if (! $wrote && ! $remove) {
            Cache::forever(self::WEATHER_KEYS[1], ['current' => [], 'hourly' => $hours]);
        }
    }
}
