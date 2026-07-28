<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:refresh-places-catalogue')]
#[Description('Refresh the source-backed Places catalogue and destination containment')]
class RefreshPlacesCatalogue extends Command
{
    public function handle(): int
    {
        foreach (['osm:import', 'parks:import-areas', 'spots:reveal-names'] as $command) {
            if ($this->call($command) !== self::SUCCESS) {
                $this->error("Places refresh stopped because {$command} failed.");

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
