<?php

namespace App\Console\Commands;

use App\Services\EventEnrichmentService;
use Illuminate\Console\Command;

class EnrichEvents extends Command
{
    protected $signature = 'events:enrich';

    protected $description = 'Enrich events with tags, expat relevance, and quality scores';

    public function handle(EventEnrichmentService $service): int
    {
        $enriched = $service->enrichAll(200);
        $this->info("Enriched {$enriched} events");

        $deduped = $service->deduplicateEvents();
        if ($deduped > 0) {
            $this->info("Removed {$deduped} duplicate events");
        }

        return self::SUCCESS;
    }
}
