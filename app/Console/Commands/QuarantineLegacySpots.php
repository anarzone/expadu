<?php

namespace App\Console\Commands;

use App\Models\Spot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spots:quarantine-legacy {--force : Quarantine the rows; without it only report the count}')]
#[Description('Quarantine pre-provenance places before an explicit authoritative rebuild')]
class QuarantineLegacySpots extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $legacy = Spot::query()->whereNull('source');
        $count = (clone $legacy)->count();

        $this->info("Legacy places without source provenance: {$count}");

        if (! $this->option('force')) {
            $this->warn('[dry-run] No rows changed. Re-run with --force immediately before a complete authoritative rebuild.');

            return self::SUCCESS;
        }

        $updated = $legacy->update([
            'is_active' => false,
            'is_recommendable' => false,
        ]);

        $this->info("Quarantined {$updated} legacy place(s). Run a complete osm:import to rebuild authoritative rows.");

        return self::SUCCESS;
    }
}
