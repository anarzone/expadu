<?php

namespace Database\Seeders;

use App\Models\Alert;
use App\Models\User;
use Illuminate\Database\Seeder;

class AlertSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::first();
        if (! $user) {
            return;
        }

        $alerts = [
            // SYSTEM
            [
                'type' => 'system', 'read_at' => null,
                'title' => 'Line 1 disrupted — major delay',
                'body' => 'Significant delays on Line 1 between Neumarkt and Deutz due to track maintenance. Expected resumption 18:30.',
                'deep_link' => '/transit',
                'created_at' => now()->subMinutes(10),
            ],
            [
                'type' => 'system', 'read_at' => null,
                'title' => 'Appointment slot available — Bürgeramt Deutz',
                'body' => 'A slot opened for tomorrow at 09:15. You have 30 minutes to book before it\'s taken.',
                'deep_link' => '/bureaucracy',
                'created_at' => now()->subMinutes(25),
            ],
            [
                'type' => 'system', 'read_at' => null,
                'title' => 'Rhine water level rising',
                'body' => 'Current level: 5.8m at Cologne gauge. Rheinufer promenade sections may be affected. No flooding expected at current trajectory.',
                'deep_link' => '/dashboard',
                'created_at' => now()->subHours(1),
            ],
            [
                'type' => 'system', 'read_at' => now(),
                'title' => 'Rain this afternoon — consider transit',
                'body' => 'Heavy rain forecast from 14:00–18:00. Bike commute score drops to 2/10. Line 9 recommended for your afternoon commute.',
                'deep_link' => '/transit',
                'created_at' => now()->subHours(3),
            ],
            [
                'type' => 'system', 'read_at' => now(),
                'title' => 'S12 running 8 minutes late',
                'body' => 'S12 from Köln Ehrenfeld Bf toward Düren is currently running 8 minutes behind schedule.',
                'deep_link' => '/transit',
                'created_at' => now()->subDay()->setTime(17, 42),
            ],
            // SOCIAL
            [
                'type' => 'social', 'read_at' => null,
                'title' => 'Sarah K. accepted your connection request',
                'body' => 'You\'re now connected. Sarah is available on weekday evenings — send her a message to arrange your first session.',
                'deep_link' => '/language-exchange',
                'created_at' => now()->subHours(2),
            ],
            [
                'type' => 'social', 'read_at' => null,
                'title' => 'New language partner match — Anna B.',
                'body' => 'Anna is a native German speaker learning English. Her profile matches your preferences. 0.3 km away in Ehrenfeld.',
                'deep_link' => '/language-exchange',
                'created_at' => now()->subHours(4),
            ],
            [
                'type' => 'social', 'read_at' => now(),
                'title' => 'Mehmet A. also joined International Expat Mixer',
                'body' => 'Mehmet is attending the event on Saturday. 32 people are going — you might already know some of them.',
                'deep_link' => '/events',
                'created_at' => now()->subDay(),
            ],
            [
                'type' => 'social', 'read_at' => now(),
                'title' => 'New tip in your area — Ehrenfeld',
                'body' => 'Carlos M. posted: "The new Turkish bakery on Venloer Str. 304 is incredible — best simit in Cologne."',
                'deep_link' => '/explore',
                'created_at' => now()->subDays(2),
            ],
            // REMINDERS
            [
                'type' => 'reminder', 'read_at' => null,
                'title' => 'Tomorrow: International Expat Mixer',
                'body' => 'You\'re going to the International Expat Mixer at Startplatz. Starts at 18:00. 32 people attending.',
                'deep_link' => '/events',
                'created_at' => now()->subMinutes(5),
            ],
            [
                'type' => 'reminder', 'read_at' => now(),
                'title' => 'Reminder: Bank account not yet opened',
                'body' => 'You\'ve been in Cologne for 2 weeks. Opening a German bank account is urgent — your employer needs an IBAN for payroll.',
                'deep_link' => '/bureaucracy',
                'created_at' => now()->subDay(),
            ],
            [
                'type' => 'reminder', 'read_at' => now(),
                'title' => 'Your Steuer-ID should arrive this week',
                'body' => 'It\'s been 3 weeks since your Anmeldung. Your tax ID letter should arrive by post any day now. Check your letterbox daily.',
                'deep_link' => '/bureaucracy',
                'created_at' => now()->subDays(2),
            ],
        ];

        foreach ($alerts as $alertData) {
            Alert::create([...$alertData, 'user_id' => $user->id]);
        }
    }
}
