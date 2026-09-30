<?php

namespace App\Console\Commands;

use App\Media\PlaceMediaCoverageAudit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[Signature('media:audit-place-coverage {--candidates=100 : Maximum attachment evidence rows to include (0-5000)}')]
#[Description('Report place-media coverage, unresolved states and retained legacy evidence without changing data')]
class AuditPlaceMediaCoverageCommand extends Command
{
    public function handle(PlaceMediaCoverageAudit $audit): int
    {
        $limit = filter_var($this->option('candidates'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 0 || $limit > 5000) {
            $this->error('Candidates must be between 0 and 5000.');

            return self::FAILURE;
        }

        $this->output->writeln(json_encode(
            $audit->report($limit),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
