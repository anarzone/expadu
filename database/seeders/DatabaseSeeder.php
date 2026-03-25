<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TaskSeeder::class,
        ]);

        User::factory()->onboarded()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call([
            SpotSeeder::class,
            EventSeeder::class,
            AlertSeeder::class,
        ]);
    }
}
