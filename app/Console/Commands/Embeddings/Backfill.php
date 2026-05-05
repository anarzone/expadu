<?php

namespace App\Console\Commands\Embeddings;

use App\Models\CityNews;
use App\Models\Concerns\HasEmbedding;
use App\Models\Event;
use App\Models\Service;
use App\Models\Spot;
use App\Services\EmbeddingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Signature('embeddings:backfill {--model= : Limit to one of: spots, events, city_news, services} {--missing : Only rows where embedding is null} {--limit= : Stop after N rows}')]
#[Description('Compute pgvector embeddings for content rows via the local Python sidecar.')]
class Backfill extends Command
{
    /** @return array<string, class-string> */
    private const MODELS = [
        'spots' => Spot::class,
        'events' => Event::class,
        'city_news' => CityNews::class,
        'services' => Service::class,
    ];

    public function handle(EmbeddingService $service): int
    {
        $only = $this->option('model');
        $missingOnly = (bool) $this->option('missing');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $targets = $only ? [$only => self::MODELS[$only] ?? null] : self::MODELS;
        $targets = array_filter($targets);

        if (empty($targets)) {
            $this->error('Unknown --model. Use one of: '.implode(', ', array_keys(self::MODELS)));

            return self::FAILURE;
        }

        $totalDone = 0;
        $totalSkipped = 0;

        foreach ($targets as $key => $modelClass) {
            $this->info("[{$key}] backfilling …");

            /** @var Builder<Model&HasEmbedding> $query */
            $query = $modelClass::query();
            if ($missingOnly) {
                $query->whereNull('embedding');
            }

            $count = (clone $query)->count();
            if ($count === 0) {
                $this->line('  no rows to process');

                continue;
            }
            $this->line("  {$count} candidate row(s)");

            $bar = $this->output->createProgressBar($limit !== null ? min($limit, $count) : $count);
            $processed = 0;
            $done = 0;
            $skipped = 0;

            $query->orderBy((new $modelClass)->getKeyName())
                ->chunkById(50, function ($rows) use ($service, &$processed, &$done, &$skipped, $bar, $limit): bool {
                    foreach ($rows as $row) {
                        if ($limit !== null && $processed >= $limit) {
                            return false;
                        }
                        $processed++;
                        if ($row->refreshEmbedding($service)) {
                            $done++;
                        } else {
                            $skipped++;
                        }
                        $bar->advance();
                    }

                    return true;
                });

            $bar->finish();
            $this->newLine();
            $this->info("  done: {$done}, skipped: {$skipped}");

            $totalDone += $done;
            $totalSkipped += $skipped;
        }

        $this->info("Total embedded: {$totalDone}, skipped: {$totalSkipped}");

        return self::SUCCESS;
    }
}
