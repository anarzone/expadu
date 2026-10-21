<?php

namespace Database\Seeders\MockSample;

use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventBookmark;
use App\Models\EventReminder;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Events' pieces of the mock's sample day (Wednesday 30 September 2026), for
 * tools/mock-compare: the mock's catalogue as listings (its two fictional
 * evening events from prototype/dev/design/connected/sample-day.js, and the
 * 8 September programme it keeps as a past sample, connected/model.js), and
 * its sample weather (events-next/sample-weather.js) as that day's hours in
 * the cached forecast. The mock's categories become the app's own. The day's plans come from `mock:sample-day` and the moment from
 * the `mock_clock` cookie. The account starts from the mock's state on these
 * events: none saved, no reminder, not going, whatever seeded it before
 * (Today's sample saves one); a config that needs one saved saves it in its
 * own steps. Local only; never runs anywhere else. Seed a
 * comparison database of your own, never the shared one:
 *
 * DB_DATABASE=expadu_s4 php artisan db:seed --class="Database\Seeders\MockSample\EventsSeeder"
 */
class EventsSeeder extends Seeder
{
    /** The mock's sample day (connected/sample-day.js). */
    public const DATE = '2026-09-30';

    private const SOURCE = 'Sample';

    private const VENUE_MARK = 'Sample venue (MockSample\EventsSeeder)';

    // Cologne, as WeatherService::getDayForecast reads it by default.
    private const WEATHER_KEYS = ['weather_fresh:50.9375_6.9603', 'weather_stale:50.9375_6.9603'];

    /** The mock's catalogue, in its order. */
    private const EVENTS = [
        ['uid' => 'today-review-culture', 'date' => self::DATE, 'title' => 'Street culture night', 'lang' => 'en', 'category' => 'culture', 'venue' => 'Rheinauhafen', 'area' => 'Neustadt-Süd', 'lat' => 50.92262, 'lng' => 6.96838, 'start' => '18:30', 'end' => '21:00', 'about' => 'An illustrative evening of music by the Rhine. Sample event for this design preview.'],
        ['uid' => 'today-review-cinema', 'date' => self::DATE, 'title' => 'Cinema under the stars', 'lang' => 'en', 'category' => 'culture', 'venue' => 'Stadtgarten', 'area' => 'Neustadt-Nord', 'lat' => 50.9445, 'lng' => 6.93694, 'start' => '20:00', 'end' => '22:00', 'about' => 'An illustrative outdoor film night. Sample event for this design preview.'],
        ['uid' => 'exhibition-ludwig', 'date' => '2026-09-08', 'title' => 'HIER UND JETZT im Museum Ludwig', 'english' => 'Here and now at Museum Ludwig', 'lang' => 'de', 'category' => 'culture', 'venue' => 'Museum Ludwig', 'area' => 'Altstadt-Nord', 'lat' => 50.94083, 'lng' => 6.96002, 'start' => '10:00', 'end' => '18:00'],
        ['uid' => 'kunst-machen', 'date' => '2026-09-08', 'title' => 'Kunst zum Mit-Machen', 'english' => 'Art to join in with', 'lang' => 'de', 'category' => 'culture', 'venue' => 'Stadtteilbibliothek Mülheim', 'area' => 'Mülheim', 'lat' => 50.96103, 'lng' => 7.0048, 'start' => '16:00', 'end' => '17:30', 'price' => 'Free', 'about' => 'Look at artworks and make your own, with Eva Degenhardt from the children’s art project KunstPostStation.', 'url' => 'https://www.stadt-koeln.de/leben-in-koeln/veranstaltungen/daten/41191/index.html'],
        ['uid' => 'schach', 'date' => '2026-09-08', 'title' => 'SchachXperten', 'english' => 'Chess experts', 'lang' => 'de', 'category' => 'sports', 'venue' => 'Stadtteilbibliothek Chorweiler', 'area' => 'Chorweiler', 'lat' => 51.02232, 'lng' => 6.89936, 'start' => '16:00', 'end' => '17:30', 'price' => 'Free', 'about' => 'Play and learn chess, from age 8.', 'url' => 'https://www.stadt-koeln.de/leben-in-koeln/veranstaltungen/daten/41126/index.html'],
        ['uid' => 'forest', 'date' => '2026-09-08', 'title' => 'Den Forst erleben – Klimawandel hautnah', 'english' => 'Experience the forest: climate change up close', 'lang' => 'de', 'category' => 'other', 'venue' => 'Waldlabor', 'area' => 'Junkersdorf', 'lat' => 50.91569, 'lng' => 6.87564, 'start' => '17:00', 'end' => '19:00', 'price' => 'Free'],
        ['uid' => 'garden', 'date' => '2026-09-08', 'title' => 'Botanischer Feierabendrundgang', 'english' => 'After-work tour of the Botanical Garden', 'lang' => 'de', 'category' => 'other', 'venue' => 'Flora und Botanischer Garten', 'area' => 'Riehl', 'lat' => 50.95911, 'lng' => 6.97242, 'start' => '18:00', 'end' => '20:00', 'price' => 'Free · donations welcome', 'about' => 'Garden lovers and anyone curious about botany discover the garden’s many plants and animals on a 45-minute short tour.', 'url' => 'https://www.stadt-koeln.de/leben-in-koeln/veranstaltungen/daten/41411/index.html'],
        ['uid' => 'tandem', 'date' => '2026-09-08', 'title' => 'Tandem Language Café', 'lang' => 'en', 'category' => 'language_exchange', 'venue' => 'VHS Studienhaus am Neumarkt', 'area' => 'Altstadt-Nord', 'lat' => 50.93493, 'lng' => 6.94999, 'start' => '18:30', 'end' => '20:00', 'price' => 'Free'],
        ['uid' => 'film', 'date' => '2026-09-08', 'title' => 'Alpen Film Fest Filmtour 2026', 'english' => 'Alpine Film Festival tour 2026', 'lang' => 'de', 'category' => 'culture', 'venue' => 'Open Air Kino Köln', 'area' => 'Altstadt-Süd', 'lat' => null, 'lng' => null, 'start' => '19:00', 'end' => null],
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

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('The mock sample is local only.');

            return;
        }

        $email = (string) env('E2E_EMAIL', 'e2e@expadu.test');
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $this->command?->warn("No account {$email}: run E2ETestUserSeeder first.");

            return;
        }

        foreach (self::EVENTS as $e) {
            // Its own venue row, never a real one of the same name.
            $venue = Venue::query()->updateOrCreate(
                ['name' => $e['venue'], 'address_text' => self::VENUE_MARK],
                ['veedel' => $e['area'], 'lat' => $e['lat'], 'lng' => $e['lng']],
            );
            $price = $e['price'] ?? null;
            Event::query()->updateOrCreate(['source_uid' => "mock:{$e['uid']}"], [
                'title' => $e['title'],
                'title_en' => $e['english'] ?? null,
                'category' => $e['category'],
                'description' => $e['about'] ?? null,
                'description_en' => $e['about'] ?? null,
                'summary_en' => $e['about'] ?? null,
                'source_lang' => $e['lang'],
                'starts_at' => CarbonImmutable::parse("{$e['date']} {$e['start']}", 'Europe/Berlin'),
                'ends_at' => $e['end'] === null ? null : CarbonImmutable::parse("{$e['date']} {$e['end']}", 'Europe/Berlin'),
                'location_name' => $e['venue'],
                'venue_id' => $venue->id,
                'is_free' => $price !== null && str_starts_with($price, 'Free'),
                'price_text' => $price === 'Free' ? null : $price,
                'language' => null,
                'organiser_id' => $user->id,
                'source' => self::SOURCE,
                'source_url' => $e['url'] ?? null,
                'status' => 'active',
                'is_curated' => true,
                'quality_score' => 1,
                'relevance' => 1,
            ]);
        }

        // As the mock starts: nothing of the account's on the sample events.
        $sample = Event::query()->where('source_uid', 'like', 'mock:%')->pluck('id');
        foreach ([EventBookmark::class, EventReminder::class, EventAttendee::class] as $model) {
            $model::query()->where('user_id', $user->id)->whereIn('event_id', $sample)->delete();
        }

        $this->weather();
    }

    /** Puts the sample day's hours in the cached forecast, keeping any other day's. */
    private function weather(): void
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
                ...$hours,
            ];
            // The fresh copy keeps a short life, so real refreshes come back.
            str_starts_with($key, 'weather_fresh:')
                ? Cache::put($key, $data, now()->addHours(2))
                : Cache::forever($key, $data);
            $wrote = true;
        }

        // Nothing cached yet: the stale copy is enough (the page reads it).
        if (! $wrote) {
            Cache::forever(self::WEATHER_KEYS[1], ['current' => [], 'hourly' => $hours]);
        }
    }
}
