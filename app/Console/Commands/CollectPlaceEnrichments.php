<?php

namespace App\Console\Commands;

use App\Places\Enrichment\PlaceEnrichmentBatches;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('places:enrich-collect')]
#[Description('Apply the results of place enrichment batches that have ended')]
class CollectPlaceEnrichments extends Command
{
    public function handle(PlaceEnrichmentBatches $batches): int
    {
        // Results already paid for are collected even if the switch was turned off since.
        if (blank(config('services.anthropic.key'))) {
            return self::SUCCESS;
        }

        foreach ($batches->collect() as $outcome => $count) {
            $this->line("  {$outcome}: {$count}");
        }

        return self::SUCCESS;
    }
}
