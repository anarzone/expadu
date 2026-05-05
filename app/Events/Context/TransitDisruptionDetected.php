<?php

namespace App\Events\Context;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransitDisruptionDetected implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $stopsAffected
     * @param  array{minLat: float, minLng: float, maxLat: float, maxLng: float}|null  $bbox
     */
    public function __construct(
        public int $disruptionId,
        public array $lines,
        public array $stopsAffected,
        public string $severity,
        public ?array $bbox,
        public ?CarbonInterface $expiresAt,
    ) {}
}
