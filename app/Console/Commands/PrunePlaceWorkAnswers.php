<?php

namespace App\Console\Commands;

use App\Places\PlaceWorkAnswers;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Deletes "Good for laptop work?" answers older than 18 months (they stop counting at 12). */
#[Signature('places:prune-work-answers')]
#[Description('Delete laptop-work answers older than 18 months and recompute those places')]
class PrunePlaceWorkAnswers extends Command
{
    public function handle(PlaceWorkAnswers $answers): int
    {
        $this->info('Deleted '.$answers->prune().' answer(s) older than '.PlaceWorkAnswers::PRUNE_MONTHS.' months.');

        return self::SUCCESS;
    }
}
