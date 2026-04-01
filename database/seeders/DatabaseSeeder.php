<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Production-safe, idempotent seeder.
 * Only creates essential system records.
 * All content comes through APIs, scrapers, or admin panel.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Settlement task definitions (bureaucracy checklist)
        $this->call(TaskSeeder::class);

        // System user for scraped content attribution
        User::firstOrCreate(
            ['email' => 'system@expadu.com'],
            [
                'name' => 'Expadu',
                'password' => bcrypt('system_no_login_'.bin2hex(random_bytes(16))),
                'email_verified_at' => now(),
                'city' => 'cologne',
            ]
        );

        // Curated work spots (real data, geocoded)
        $this->call(SpotSeeder::class);
    }
}
