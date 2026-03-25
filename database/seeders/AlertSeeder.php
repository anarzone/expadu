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
            ['type' => 'system', 'title' => 'Line 1 delays — FC Köln match', 'body' => 'Expect overcrowding on Line 1 from 19:00. Use Line 9 via Ehrenfeld.', 'deep_link' => '/transit', 'created_at' => now()->subMinutes(10)],
            ['type' => 'system', 'title' => 'Bürgeramt slot available', 'body' => 'A slot opened at Bürgeramt Deutz for tomorrow at 09:15.', 'deep_link' => '/bureaucracy', 'created_at' => now()->subMinutes(25)],
            ['type' => 'social', 'title' => 'Sarah accepted your language request', 'body' => 'You can now chat with Sarah. She speaks German (C1) and wants to practice English.', 'deep_link' => '/chat', 'created_at' => now()->subHours(1)],
            ['type' => 'social', 'title' => 'New partner match found', 'body' => 'Anna speaks Turkish and wants to practise English — 92% compatibility.', 'deep_link' => '/language-exchange', 'created_at' => now()->subHours(3)],
            ['type' => 'reminder', 'title' => 'Bürgeramt appointment tomorrow', 'body' => 'Bürgeramt Deutz at 09:15. Bring: Anmeldeformular, Passport, Rental contract.', 'deep_link' => '/bureaucracy', 'created_at' => now()->subHours(5)],
            ['type' => 'system', 'title' => 'Rhine flood warning', 'body' => 'Rhine level rising. Rheinufer promenade may close. Check before cycling.', 'deep_link' => '/transit', 'created_at' => now()->subDay()],
            ['type' => 'reminder', 'title' => 'Tax ID not received yet', 'body' => 'It has been 30 days since your Anmeldung. Consider contacting the Finanzamt.', 'deep_link' => '/bureaucracy', 'created_at' => now()->subDays(2)],
            ['type' => 'social', 'title' => 'Language Exchange Night this Saturday', 'body' => '32 people going to Café Schmitz this Saturday at 19:00.', 'deep_link' => '/events', 'created_at' => now()->subDays(2)],
        ];

        foreach ($alerts as $alertData) {
            Alert::create([...$alertData, 'user_id' => $user->id]);
        }
    }
}
