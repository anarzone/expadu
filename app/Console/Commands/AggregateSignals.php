<?php

namespace App\Console\Commands;

use App\Personalisation\AffinityAggregator;
use Illuminate\Console\Command;

class AggregateSignals extends Command
{
    protected $signature = 'signals:aggregate';

    protected $description = 'Rebuild decayed personalisation affinities and place negatives from the signal ledger';

    public function handle(AffinityAggregator $aggregator): int
    {
        $started = microtime(true);
        $written = $aggregator->rebuild();

        $this->info(sprintf(
            'Wrote %d affinities and %d place negatives in %.2fs.',
            $written['affinities'],
            $written['place_negatives'],
            microtime(true) - $started,
        ));

        return self::SUCCESS;
    }
}
