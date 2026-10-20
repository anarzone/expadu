<?php

namespace App\Console\Commands;

use App\Places\RecordPlaceObservation;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\Console\Output\OutputInterface;

#[Signature('places:restore-observation {observation : Historical observation ID to restore}
    {--apply : Append the selected historical snapshot; default is a read-only preview}
    {--expected-current-hash= : Current payload hash shown by the reviewed preview}
    {--actor= : Reviewer or operator identifier}
    {--reason= : Documented reason for restoring the source snapshot}')]
#[Description('Preview or safely restore a historical place-source observation')]
class RestorePlaceObservation extends Command
{
    public function handle(RecordPlaceObservation $recorder): int
    {
        $observation = (string) $this->argument('observation');
        if (! ctype_digit($observation) || (int) $observation < 1) {
            $this->error('Observation ID must be a positive integer.');

            return self::FAILURE;
        }

        try {
            $preview = $recorder->previewRestore((int) $observation);
            $applied = false;
            if ($this->option('apply')) {
                $applied = $recorder->restore(
                    (int) $observation,
                    (string) $this->option('expected-current-hash'),
                    (string) $this->option('actor'),
                    (string) $this->option('reason'),
                );
                $preview = $recorder->previewRestore((int) $observation);
            }
        } catch (DomainException|ModelNotFoundException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->output->writeln(json_encode(
            ['applied' => $applied, ...$preview],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
