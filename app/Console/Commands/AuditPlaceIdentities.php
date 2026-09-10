<?php

namespace App\Console\Commands;

use App\Places\PlaceIdentityAudit;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;

class AuditPlaceIdentities extends Command
{
    protected $signature = 'places:audit-identities {--radius=10 : Candidate search radius in metres, up to 100}';

    protected $description = 'Report possible legacy place duplicates and reference risks without changing the catalogue';

    public function handle(PlaceIdentityAudit $audit): int
    {
        $radius = $this->option('radius');
        if (! is_numeric($radius)) {
            $this->error('Radius must be a number of metres.');

            return self::FAILURE;
        }

        try {
            $report = $audit->report((float) $radius);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
