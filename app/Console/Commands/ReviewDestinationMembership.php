<?php

namespace App\Console\Commands;

use App\Places\DestinationGrouping;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\Console\Output\OutputInterface;

class ReviewDestinationMembership extends Command
{
    protected $signature = 'places:review-destination {spot : Facility or venue ID} {destination : Destination ID or independent}
        {--apply : Save the reviewed decision; default is a read-only preview}
        {--fingerprint= : Fingerprint from the reviewed preview}
        {--evidence= : Source evidence for component or independent operation}';

    protected $description = 'Preview or record an evidence-reviewed destination membership without changing place identity';

    public function handle(DestinationGrouping $grouping): int
    {
        $spot = (string) $this->argument('spot');
        $destination = (string) $this->argument('destination');
        if (! ctype_digit($spot) || (int) $spot < 1 || ($destination !== 'independent' && (! ctype_digit($destination) || (int) $destination < 1))) {
            $this->error('Use positive place IDs, or independent for the destination.');

            return self::FAILURE;
        }
        $destinationId = $destination === 'independent' ? null : (int) $destination;
        try {
            if ($this->option('apply')) {
                $grouping->review((int) $spot, $destinationId, (string) $this->option('evidence'), (string) $this->option('fingerprint'));
            }
            $preview = $grouping->preview((int) $spot, $destinationId);
        } catch (DomainException|ModelNotFoundException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->output->writeln(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
