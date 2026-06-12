<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Production-safe, idempotent seeder.
 * Only creates essential system records.
 * All content comes through APIs, scrapers, or admin panel.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Bureaucracy catalogue: the YAML files are the single source of
        // truth — the importer compiles + upserts them (idempotent). The
        // legacy TaskSeeder is gone; it kept recreating keyless pre-v2 rows
        // on every container start, racing the deploy-time prune.
        Artisan::call('bureaucracy:import-tasks', ['--prune' => true]);

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
