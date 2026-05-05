<?php

namespace App\Events\Context;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserContextChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $userId,
        public string $contextType,
        public ?int $placeId,
        public CarbonInterface $at,
    ) {}
}
