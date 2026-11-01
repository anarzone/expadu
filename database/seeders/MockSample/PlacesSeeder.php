<?php

namespace Database\Seeders\MockSample;

use App\Models\Spot;
use Illuminate\Database\Seeder;

/**
 * The everyday places around Wiener Platz that the mock's sample day shows
 * under "Open near Wiener Platz at 14:30" (prototype/dev/design/connected/
 * sample-places.js: Expadu's own catalogue export of 7 October 2026, place
 * data © OpenStreetMap contributors, ODbL), for tools/mock-compare, and the
 * facts the sample records for them (step-free access, website, phone, food
 * and Wi-Fi), which Around here's rows and place sheet read. Local only; it
 * adds the places a database is missing and the facts a place is missing,
 * and never changes a value that is already there.
 *
 * php artisan db:seed --class="Database\Seeders\MockSample\PlacesSeeder"
 */
class PlacesSeeder extends Seeder
{
    /** @var list<array{0: string, 1: string, 2: float, 3: float, 4: ?string, 5: string}> name, category, lat, lng, OSM opening_hours, OSM id */
    private const PLACES = [
        ['Eck-Apotheke', 'pharmacy', 50.961116, 7.005841, 'Mo-Fr 08:30-18:30; Sa 09:00-14:00', 'node/3354736381'],
        ['Einhorn-Apotheke', 'pharmacy', 50.962394, 7.004031, 'Mo-Fr 08:00-19:00; Sa 09:00-14:00', 'node/317630493'],
        ['Brücken-Apotheke', 'pharmacy', 50.96261, 7.003429, 'Mo-Fr 08:30-19:00; Sa 10:00-15:00', 'node/343578721'],
        ['Bau', 'cafe', 50.960192, 7.006691, 'Mo,We-Fr 07:30-19:30; Sa 08:00-17:00; Su 09:00-18:00', 'node/1593180033'],
        ['Eiscafé Dolomiti', 'cafe', 50.959678, 7.007916, 'Mo-Su 10:00-22:00', 'node/2104161354'],
        ['Yoop. Frozen Yogurt', 'cafe', 50.964297, 7.000022, null, 'node/4422129390'],
        ['Kamps', 'bakery', 50.961134, 7.005729, 'Mo-Fr 06:30-18:30; Sa 06:30-15:00; Su 07:00-13:00', 'node/369748924'],
        ['Bäckerei Schneider', 'bakery', 50.962563, 7.003558, 'Mo-Sa 06:00-19:00; Su 08:00-12:00', 'node/8731466558'],
        ['dm', 'drugstore', 50.962134, 7.00379, 'Mo-Sa 08:00-20:00', 'node/12442050740'],
        ['REWE', 'supermarket', 50.962625, 7.003478, 'Mo-Sa 07:00-24:00', 'node/1841208275'],
    ];

    /**
     * What the sample records for each place (sample-places.js), by OSM id:
     * its tags, and its website and phone (which a place keeps in its own
     * columns, not its tags). Nothing else is recorded there.
     *
     * @var array<string, array<string, string>>
     */
    private const TAGS = [
        'node/3354736381' => ['wheelchair' => 'yes', 'website' => 'https://eck-apotheke.de/', 'phone' => '+49 221 612590'],
        'node/317630493' => ['wheelchair' => 'yes', 'website' => 'http://www.einhornapothekekoeln.de/index1.htm', 'phone' => '+49 221 611160'],
        'node/343578721' => ['wheelchair' => 'yes', 'website' => 'https://www.brueckenapotheke-koeln.de/', 'phone' => '+49 221 9639970'],
        'node/1593180033' => ['wheelchair' => 'no', 'outdoor_seating' => 'yes'],
        'node/2104161354' => ['wheelchair' => 'limited', 'phone' => '+49 221 611664', 'cuisine' => 'ice_cream;coffee_shop', 'outdoor_seating' => 'no', 'diet:vegetarian' => 'yes'],
        'node/4422129390' => ['diet:vegetarian' => 'yes'],
        'node/369748924' => ['wheelchair' => 'yes', 'outdoor_seating' => 'yes'],
        'node/12442050740' => ['wheelchair' => 'yes'],
        'node/1841208275' => ['wheelchair' => 'limited', 'phone' => '+49 221 9624053', 'internet_access' => 'wlan'],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('The mock sample is local only.');

            return;
        }

        $added = 0;
        $filled = 0;
        foreach (self::PLACES as [$name, $category, $lat, $lng, $hours, $osm]) {
            $spot = Spot::query()->firstOrCreate(
                ['source' => 'osm', 'source_id' => $osm],
                [
                    'name' => $name,
                    'category' => $category,
                    'lat' => $lat,
                    'lng' => $lng,
                    'veedel' => 'Mülheim',
                    'tags' => $hours === null ? [] : ['opening_hours' => $hours],
                    'is_active' => true,
                    'public_access_allowed' => true,
                ],
            );
            $added += $spot->wasRecentlyCreated ? 1 : 0;

            // Only what it lacks: a value already there is never changed (the
            // same OSM place can be a real catalogue row).
            $recorded = self::TAGS[$osm] ?? [];
            $contact = array_filter(
                array_intersect_key($recorded, array_flip(['website', 'phone'])),
                fn (string $value, string $column): bool => $spot->{$column} === null,
                ARRAY_FILTER_USE_BOTH,
            );
            $tags = is_array($spot->tags) ? $spot->tags : [];
            $missing = array_diff_key(array_diff_key($recorded, array_flip(['website', 'phone'])), $tags);
            if ($missing !== [] || $contact !== []) {
                $spot->forceFill([...$contact, 'tags' => [...$tags, ...$missing]])->save();
                $filled += count($missing) + count($contact);
            }
        }

        $this->command?->info("{$added} of ".count(self::PLACES)." sample places added around Wiener Platz; {$filled} missing facts filled.");
    }
}
