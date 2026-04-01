<?php

namespace Database\Seeders;

use App\Models\Spot;
use App\Services\GeocodingService;
use Illuminate\Database\Seeder;

/**
 * Seeds curated expat-friendly work spots in Cologne.
 * Idempotent — skips spots that already exist by name.
 * Sources: laptopfriendly.co/cologne, Starbucks, Coffee Fellows, Cafe Nova, libraries, coworking.
 */
class SpotSeeder extends Seeder
{
    public function run(): void
    {
        $spots = [
            // laptopfriendly.co/cologne
            ['name' => 'AYNI x Schanze', 'category' => 'cafe', 'address' => 'Schanzenstraße 6, Köln', 'wifi_speed' => 'fast', 'noise_level' => 'moderate'],
            ['name' => 'AYNI em Stüverhoff', 'category' => 'cafe', 'address' => 'Im Stavenhof 6, Köln', 'wifi_speed' => 'fast', 'noise_level' => 'quiet'],
            ['name' => 'THE COFFICE DEUTZ', 'category' => 'coworking', 'address' => 'Charles-de-Gaulle-Platz 1d, Köln', 'wifi_speed' => 'fast', 'noise_level' => 'quiet'],
            ['name' => 'URBAN LOFT Cologne', 'category' => 'coworking', 'address' => 'Eigelstein 41, Köln', 'wifi_speed' => 'fast', 'noise_level' => 'moderate'],
            ['name' => 'Café Heimisch', 'category' => 'cafe', 'address' => 'Deutzer Freiheit 89, Köln', 'wifi_speed' => 'decent', 'noise_level' => 'moderate'],
            ['name' => 'Espresso House', 'category' => 'cafe', 'address' => 'Bonner Str. 22, Köln', 'wifi_speed' => 'decent', 'noise_level' => 'moderate'],
            ['name' => 'Impact Café by Plastic2Beans', 'category' => 'cafe', 'address' => 'Luxemburger Str. 190, Köln', 'wifi_speed' => 'decent', 'noise_level' => 'quiet'],
            ['name' => 'Goldmund Literatur Café', 'category' => 'cafe', 'address' => 'Glasstraße 2, Köln', 'wifi_speed' => 'decent', 'noise_level' => 'quiet'],
            ['name' => 'Kaffeesaurus', 'category' => 'cafe', 'address' => 'Friesenplatz 15, Köln', 'wifi_speed' => 'decent', 'noise_level' => 'moderate'],
            ['name' => 'Die Mehlwerkstatt', 'category' => 'cafe', 'address' => 'Venloer Str. 202, Köln', 'wifi_speed' => 'decent', 'noise_level' => 'quiet'],
            // Coffee Fellows
            ['name' => 'Coffee Fellows Mediapark', 'category' => 'cafe', 'address' => 'Im Mediapark 1, Köln', 'wifi_speed' => 'fast', 'noise_level' => 'moderate'],
            ['name' => 'Coffee Fellows Karolingerring', 'category' => 'cafe', 'address' => 'Karolingerring 2, Köln', 'wifi_speed' => 'fast', 'noise_level' => 'moderate'],
            ['name' => 'Coffee Fellows Antonsgasse', 'category' => 'cafe', 'address' => 'Antonsgasse 7, Köln', 'wifi_speed' => 'fast', 'noise_level' => 'moderate'],
            ['name' => 'Coffee Fellows Hbf', 'category' => 'cafe', 'address' => 'Trankgasse 11, Köln', 'wifi_speed' => 'fast', 'noise_level' => 'loud'],
            // Starbucks
            ['name' => 'Starbucks Hohenzollernring', 'category' => 'cafe', 'address' => 'Hohenzollernring 70, Köln', 'wifi_speed' => 'fast', 'noise_level' => 'loud'],
            ['name' => 'Starbucks Schildergasse', 'category' => 'cafe', 'address' => 'Schildergasse 67, Köln', 'wifi_speed' => 'fast', 'noise_level' => 'loud'],
            ['name' => 'Starbucks Neumarkt', 'category' => 'cafe', 'address' => 'Neumarkt 1, Köln', 'wifi_speed' => 'fast', 'noise_level' => 'loud'],
            ['name' => 'Starbucks Hbf', 'category' => 'cafe', 'address' => 'Köln Hauptbahnhof, Köln', 'wifi_speed' => 'fast', 'noise_level' => 'loud'],
            ['name' => 'Starbucks Ehrenstraße', 'category' => 'cafe', 'address' => 'Ehrenstraße 72, Köln', 'wifi_speed' => 'fast', 'noise_level' => 'loud'],
            ['name' => 'Starbucks Rudolfplatz', 'category' => 'cafe', 'address' => 'Rudolfplatz 3, Köln', 'wifi_speed' => 'fast', 'noise_level' => 'moderate'],
            // Cafe Nova
            ['name' => 'Cafe Nova', 'category' => 'cafe', 'address' => 'Breite Str. 64, Köln', 'wifi_speed' => 'decent', 'noise_level' => 'moderate'],
            ['name' => 'Cafe Nova Deutz', 'category' => 'cafe', 'address' => 'Deutzer Freiheit 72, Köln', 'wifi_speed' => 'decent', 'noise_level' => 'moderate'],
            // Libraries
            ['name' => 'Stadtbibliothek Köln', 'category' => 'library', 'address' => 'Josef-Haubrich-Hof 1, Köln', 'wifi_speed' => 'fast', 'noise_level' => 'quiet'],
            ['name' => 'Stadtteilbibliothek Ehrenfeld', 'category' => 'library', 'address' => 'Subbelrather Str. 247a, Köln', 'wifi_speed' => 'decent', 'noise_level' => 'quiet'],
            ['name' => 'Stadtteilbibliothek Nippes', 'category' => 'library', 'address' => 'Neusser Str. 450, Köln', 'wifi_speed' => 'decent', 'noise_level' => 'quiet'],
            // Coworking
            ['name' => 'Startplatz', 'category' => 'coworking', 'address' => 'Im Mediapark 5, Köln', 'wifi_speed' => 'fast', 'noise_level' => 'moderate'],
            ['name' => 'Design Offices Köln Dominium', 'category' => 'coworking', 'address' => 'Tunisstr. 19-23, Köln', 'wifi_speed' => 'fast', 'noise_level' => 'quiet'],
            ['name' => 'WeWork Friesenplatz', 'category' => 'coworking', 'address' => 'Hohenzollernring 85-87, Köln', 'wifi_speed' => 'fast', 'noise_level' => 'moderate'],
            ['name' => 'JUNO Coworking', 'category' => 'coworking', 'address' => 'Jülicher Str. 72a, Köln', 'wifi_speed' => 'fast', 'noise_level' => 'quiet'],
            ['name' => 'Colabor Ehrenfeld', 'category' => 'coworking', 'address' => 'Vogelsanger Str. 187, Köln', 'wifi_speed' => 'fast', 'noise_level' => 'moderate'],
        ];

        $geocode = app(GeocodingService::class);
        $created = 0;

        foreach ($spots as $s) {
            if (Spot::where('name', $s['name'])->exists()) {
                continue;
            }

            $results = $geocode->search($s['address']);
            $first = $results[0] ?? null;

            if (! $first) {
                $this->command?->warn("Skipped (no coords): {$s['name']}");

                continue;
            }

            Spot::create([
                'name' => $s['name'],
                'category' => $s['category'],
                'address' => $s['address'],
                'lat' => $first['lat'],
                'lng' => $first['lng'],
                'wifi_speed' => $s['wifi_speed'],
                'noise_level' => $s['noise_level'],
            ]);
            $created++;
            usleep(200000); // geocoder rate limit
        }

        $this->command?->info("Spots: {$created} created, ".(Spot::count()).' total');
    }
}
