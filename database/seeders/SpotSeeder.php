<?php

namespace Database\Seeders;

use App\Models\Spot;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SpotSeeder extends Seeder
{
    public function run(): void
    {
        $spots = [
            ['name' => 'Café Schmitz', 'category' => 'cafe', 'description' => 'Cozy café with great WiFi in Ehrenfeld', 'address' => 'Venloer Str. 236, Ehrenfeld', 'wifi_speed' => 'fast', 'noise_level' => 'quiet', 'time_limit_mins' => null, 'rating' => 4.8, 'lat' => 50.9478, 'lng' => 6.9183],
            ['name' => 'Startplatz', 'category' => 'coworking', 'description' => 'Cologne startup hub with hot desks and meeting rooms', 'address' => 'Im Mediapark 5, Neustadt-Nord', 'wifi_speed' => 'fast', 'noise_level' => 'moderate', 'time_limit_mins' => null, 'rating' => 4.6, 'lat' => 50.9467, 'lng' => 6.9417],
            ['name' => 'StadtBibliothek Köln', 'category' => 'library', 'description' => 'Central city library with silent study areas', 'address' => 'Josef-Haubrich-Hof 1, Neustadt-Süd', 'wifi_speed' => 'fast', 'noise_level' => 'quiet', 'time_limit_mins' => null, 'rating' => 4.7, 'lat' => 50.9339, 'lng' => 6.9479],
            ['name' => 'Wohnzimmer', 'category' => 'cafe', 'description' => 'Living room-style café, perfect for long work sessions', 'address' => 'Aachener Str. 70, Ehrenfeld', 'wifi_speed' => 'ok', 'noise_level' => 'moderate', 'time_limit_mins' => 180, 'rating' => 4.4, 'lat' => 50.9411, 'lng' => 6.9228],
            ['name' => 'COWOKI Ehrenfeld', 'category' => 'coworking', 'description' => 'Flexible coworking with day passes', 'address' => 'Lichtstr. 25, Ehrenfeld', 'wifi_speed' => 'fast', 'noise_level' => 'quiet', 'time_limit_mins' => null, 'rating' => 4.5, 'lat' => 50.9452, 'lng' => 6.9211],
            ['name' => 'Uni-Bibliothek Köln', 'category' => 'library', 'description' => 'University library open to all with student-friendly hours', 'address' => 'Universitätsstr. 33, Lindenthal', 'wifi_speed' => 'fast', 'noise_level' => 'quiet', 'time_limit_mins' => null, 'rating' => 4.3, 'lat' => 50.9280, 'lng' => 6.9286],
            ['name' => 'Rheinpark', 'category' => 'park', 'description' => 'Beautiful park along the Rhine with benches and shade', 'address' => 'Rheinparkweg, Deutz', 'wifi_speed' => null, 'noise_level' => 'quiet', 'time_limit_mins' => null, 'rating' => 4.9, 'lat' => 50.9414, 'lng' => 6.9736],
            ['name' => 'Café Sehnsucht', 'category' => 'cafe', 'description' => 'Hip café with power outlets at every table', 'address' => 'Kyffhäuserstr. 36, Südstadt', 'wifi_speed' => 'fast', 'noise_level' => 'moderate', 'time_limit_mins' => 90, 'rating' => 4.5, 'lat' => 50.9241, 'lng' => 6.9442],
            ['name' => 'Design Offices Mediapark', 'category' => 'coworking', 'description' => 'Premium coworking with ergonomic desks and lounge', 'address' => 'Im Mediapark 8, Neustadt-Nord', 'wifi_speed' => 'fast', 'noise_level' => 'quiet', 'time_limit_mins' => null, 'rating' => 4.7, 'lat' => 50.9471, 'lng' => 6.9399],
            ['name' => 'Volksgarten', 'category' => 'park', 'description' => 'Large park in Südstadt with a pond and outdoor café', 'address' => 'Volksgartenstr., Südstadt', 'wifi_speed' => null, 'noise_level' => 'quiet', 'time_limit_mins' => null, 'rating' => 4.6, 'lat' => 50.9218, 'lng' => 6.9499],
            ['name' => 'Heilandt Kaffeemanufaktur', 'category' => 'cafe', 'description' => 'Specialty coffee roasters with work-friendly atmosphere', 'address' => 'Hohenstaufenring 19, Neustadt-Süd', 'wifi_speed' => 'ok', 'noise_level' => 'moderate', 'time_limit_mins' => 120, 'rating' => 4.6, 'lat' => 50.9308, 'lng' => 6.9407],
            ['name' => 'Zentralbibliothek', 'category' => 'library', 'description' => 'Modern central library with digital workstations', 'address' => 'Josef-Haubrich-Hof 1, Neustadt-Süd', 'wifi_speed' => 'fast', 'noise_level' => 'quiet', 'time_limit_mins' => null, 'rating' => 4.5, 'lat' => 50.9340, 'lng' => 6.9478],
        ];

        foreach ($spots as $spotData) {
            $lat = $spotData['lat'];
            $lng = $spotData['lng'];
            unset($spotData['lat'], $spotData['lng']);

            $spot = Spot::create($spotData);
            DB::statement('UPDATE spots SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?', [$lng, $lat, $spot->id]);
        }
    }
}
