<?php

namespace App\Events\Context;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BuergeramtSlotsAvailable implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /** @param  list<string>  $dates */
    public function __construct(
        public string $officeId,
        public array $dates,
    ) {}
}
