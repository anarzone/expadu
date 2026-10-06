<?php

namespace Database\Seeders;

use App\Models\Spot;
use Illuminate\Database\Seeder;

/** Explicit local/browser-test fixtures only; never registered in DatabaseSeeder. */
class E2EComposerCatalogueSeeder extends Seeder
{
    public const DESCRIPTION = 'Temporary Composer browser-test fixture. Not a real destination.';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \LogicException('Composer fixtures are only available locally or in tests.');
        }

        foreach (['park', 'cafe', 'museum', 'library', 'park', 'cafe'] as $index => $category) {
            Spot::query()->updateOrCreate(['name' => 'Sample Composer test place '.($index + 1), 'description' => self::DESCRIPTION], [
                'category' => $category, 'veedel' => 'Ehrenfeld',
                'lat' => 50.95 + $index * 0.0005, 'lng' => 6.92 + $index * 0.0005,
                'is_active' => true, 'is_recommendable' => true,
                'opening_hours' => array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], [['08:00', '23:00']]),
            ]);
        }
    }
}
