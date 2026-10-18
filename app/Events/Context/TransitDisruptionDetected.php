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
     * @param  int|null  $syntheticUserId  Set only by the synthetic canary: the event is not a real
     *                                     disruption and reaches this one user, never the onboarded base.
     */
    public function __construct(
        public int $disruptionId,
        public array $lines,
        public array $stopsAffected,
        public string $severity,
        public ?array $bbox,
        public ?CarbonInterface $expiresAt,
        public ?int $syntheticUserId = null,
    ) {}

    public function isSynthetic(): bool
    {
        return $this->syntheticUserId !== null;
    }
}
