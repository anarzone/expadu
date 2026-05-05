<?php

namespace App\Events\Context;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransitDelayDetected implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $line,
        public string $direction,
        public int $delayMin,
        public ?string $stopId = null,
    ) {}
}
