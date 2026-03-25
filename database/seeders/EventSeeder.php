<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Seeder;

class EventSeeder extends Seeder
{
    public function run(): void
    {
        $organiser = User::first() ?? User::factory()->onboarded()->create();

        $events = [
            ['title' => 'Language Exchange Night', 'emoji' => '🗣️', 'category' => 'language', 'description' => 'Practice German, English, and more with fellow expats and locals. All levels welcome!', 'starts_at' => now()->addDays(2)->setTime(19, 0), 'ends_at' => now()->addDays(2)->setTime(21, 30), 'location_name' => 'Café Schmitz', 'address' => 'Venloer Str. 236, Ehrenfeld', 'max_attendees' => 30, 'is_free' => true],
            ['title' => 'Expat Stammtisch Cologne', 'emoji' => '🍺', 'category' => 'social', 'description' => 'Monthly gathering for internationals in Cologne. Come meet new people!', 'starts_at' => now()->addDays(5)->setTime(19, 30), 'ends_at' => now()->addDays(5)->setTime(22, 0), 'location_name' => 'Brauhaus Früh', 'address' => 'Am Hof 12-18, Altstadt', 'max_attendees' => 50, 'is_free' => true],
            ['title' => 'German for Beginners Workshop', 'emoji' => '📚', 'category' => 'language', 'description' => 'Intensive 2-hour workshop covering essential German phrases for daily life.', 'starts_at' => now()->addDays(3)->setTime(10, 0), 'ends_at' => now()->addDays(3)->setTime(12, 0), 'location_name' => 'VHS Köln', 'address' => 'Cäcilienstr. 35, Centrum', 'max_attendees' => 20, 'is_free' => false, 'price' => 15.00],
            ['title' => 'Kölner Lichter', 'emoji' => '🎆', 'category' => 'culture', 'description' => 'Annual fireworks festival on the Rhine. Best viewed from Deutzer Brücke.', 'starts_at' => now()->addDays(10)->setTime(20, 0), 'ends_at' => now()->addDays(10)->setTime(23, 0), 'location_name' => 'Rhine Promenade', 'address' => 'Deutzer Brücke, Cologne', 'max_attendees' => null, 'is_free' => true],
            ['title' => 'Morning Yoga in Volksgarten', 'emoji' => '🧘', 'category' => 'sports', 'description' => 'Free outdoor yoga session every Saturday morning. Bring your own mat.', 'starts_at' => now()->addDays(4)->setTime(8, 0), 'ends_at' => now()->addDays(4)->setTime(9, 0), 'location_name' => 'Volksgarten', 'address' => 'Volksgartenstr., Südstadt', 'max_attendees' => 25, 'is_free' => true],
            ['title' => 'International Food Market', 'emoji' => '🍕', 'category' => 'food', 'description' => 'Street food from around the world at Ehrenfeld market.', 'starts_at' => now()->addDays(7)->setTime(11, 0), 'ends_at' => now()->addDays(7)->setTime(20, 0), 'location_name' => 'Heliosstr.', 'address' => 'Heliosstr. 37, Ehrenfeld', 'max_attendees' => null, 'is_free' => true],
            ['title' => 'Bureaucracy Help Desk', 'emoji' => '🏛️', 'category' => 'social', 'description' => 'Get help with your Anmeldung, Ausländerbehörde, and other German paperwork. Experienced expats help newcomers.', 'starts_at' => now()->addDays(6)->setTime(14, 0), 'ends_at' => now()->addDays(6)->setTime(16, 0), 'location_name' => 'Startplatz', 'address' => 'Im Mediapark 5', 'max_attendees' => 15, 'is_free' => true],
            ['title' => 'Live Jazz at Stadtgarten', 'emoji' => '🎵', 'category' => 'culture', 'description' => 'Weekly jazz night featuring local and international artists.', 'starts_at' => now()->addDays(1)->setTime(20, 0), 'ends_at' => now()->addDays(1)->setTime(23, 0), 'location_name' => 'Stadtgarten', 'address' => 'Venloer Str. 40, Neustadt-Nord', 'max_attendees' => 80, 'is_free' => false, 'price' => 12.00],
            ['title' => 'Running Club Rhein', 'emoji' => '🏃', 'category' => 'sports', 'description' => '5K along the Rhine every Wednesday evening. All paces welcome.', 'starts_at' => now()->addDays(3)->setTime(18, 30), 'ends_at' => now()->addDays(3)->setTime(19, 30), 'location_name' => 'Rheinpark', 'address' => 'Rheinparkweg, Deutz', 'max_attendees' => 40, 'is_free' => true],
            ['title' => 'Cologne Museum Night', 'emoji' => '🏛️', 'category' => 'culture', 'description' => 'All major Cologne museums open until midnight with one ticket.', 'starts_at' => now()->addDays(14)->setTime(19, 0), 'ends_at' => now()->addDays(14)->setTime(24, 0), 'location_name' => 'Various Museums', 'address' => 'Cologne City Centre', 'max_attendees' => null, 'is_free' => false, 'price' => 18.00],
        ];

        foreach ($events as $eventData) {
            Event::create([...$eventData, 'organiser_id' => $organiser->id]);
        }
    }
}
