<?php

namespace App\Console\Commands;

use App\Models\Spot;
use App\Models\SpotEnrichment;
use App\Models\SpotEnrichmentBatch;
use App\Places\Enrichment\EnrichmentBudget;
use App\Places\Enrichment\PlaceEnricher;
use App\Places\Enrichment\PlaceEnrichmentBatches;
use App\Places\Enrichment\PlaceEnrichmentInput;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('places:enrich
    {--limit= : At most this many model requests (places with too little data are stored without one)}
    {--category= : Only these categories, comma-separated (e.g. cafe,park)}
    {--sync : Call the model now, a few at a time, instead of submitting a Message Batch}
    {--force : Re-run places whose input has not changed}')]
#[Description('Describe and tag places from their OSM data, with evidence for every tag')]
class EnrichPlaces extends Command
{
    public function handle(PlaceEnricher $enricher, PlaceEnrichmentBatches $batches, EnrichmentBudget $budget): int
    {
        if (! $enricher->isConfigured()) {
            $this->info('Place enrichment is off or there is no ANTHROPIC_API_KEY; nothing sent.');

            return self::SUCCESS;
        }

        $limit = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : PHP_INT_MAX;
        $categories = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('category')))));
        $inFlight = array_flip(SpotEnrichmentBatch::openSpotIds());
        $queue = [];
        $counts = ['unchanged' => 0, 'refreshed_unchanged' => 0, 'no_model' => 0, 'in_batch' => 0];

        Spot::query()->canonical()->where('is_active', true)
            ->when($categories !== [], fn ($query) => $query->whereIn('category', $categories))
            ->chunkById(500, function (Collection $spots) use (&$queue, &$counts, $inFlight, $limit, $enricher): bool {
                $rows = SpotEnrichment::query()->where('locale', 'en')->whereIn('spot_id', $spots->modelKeys())->get()->keyBy('spot_id');
                foreach ($spots as $spot) {
                    if (isset($inFlight[$spot->id])) {
                        $counts['in_batch']++;

                        continue;
                    }
                    $input = PlaceEnrichmentInput::fromSpot($spot);
                    $row = $rows->get($spot->id);
                    if (! $this->option('force') && $row !== null && $row->source_hash === $input->hash() && $row->status !== 'pending') {
                        if ($row->status === 'stale') {
                            $enricher->confirmUnchanged($row);
                            $counts['refreshed_unchanged']++;
                        } else {
                            $counts['unchanged']++;
                        }

                        continue;
                    }
                    if ($input->isThin()) {
                        $enricher->recordWithoutModel($input);
                        $counts['no_model']++;

                        continue;
                    }
                    if (count($queue) >= $limit) {
                        return false;
                    }
                    $queue[] = $input;
                }

                return true;
            });

        $this->line(sprintf('Unchanged %d, stale but unchanged %d, too little data %d, waiting in a batch %d, to send %d.',
            $counts['unchanged'], $counts['refreshed_unchanged'], $counts['no_model'], $counts['in_batch'], count($queue)));
        if ($queue === []) {
            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $outcomes = $enricher->enrichNow($queue, $budget);
            foreach ($outcomes as $outcome => $count) {
                $this->line("  {$outcome}: {$count}");
            }
            if (isset($outcomes['budget_reached'])) {
                $this->warn('Daily place enrichment budget reached.');
            }

            return self::SUCCESS;
        }

        $result = $batches->submit($queue, $budget);
        foreach ($result['batches'] as $batch) {
            $this->line("  Batch {$batch->provider_batch_id}: {$batch->request_count} requests, about \${$batch->estimated_cost_usd} at most.");
        }
        if ($result['budget_reached']) {
            $this->warn('Daily place enrichment budget reached; submitted '.$result['submitted'].' of '.count($queue).'.');
        }

        return self::SUCCESS;
    }
}
