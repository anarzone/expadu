<?php

namespace App\Console\Commands;

use App\Places\PlaceFeatures;
use Illuminate\Console\Command;

class BuildPlaceFeatures extends Command
{
    protected $signature = 'places:build-features';

    protected $description = 'Rebuild place_features from the effective place facts';

    public function handle(PlaceFeatures $features): int
    {
        $this->info('Built features for '.$features->rebuild().' places.');

        return self::SUCCESS;
    }
}
