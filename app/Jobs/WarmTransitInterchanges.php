<?php

namespace App\Jobs;

use App\Transit\TransitousAdapter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Geocodes the interchange stops "Show more options" routes through, off the
 * request path. Queued by the adapter on a cache miss; unique per engine so a
 * burst of journey requests queues one warm-up, not one each.
 */
class WarmTransitInterchanges implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public int $uniqueFor = 600;

    /**
     * @param  class-string<TransitousAdapter>  $adapter
     */
    public function __construct(public string $adapter) {}

    public function uniqueId(): string
    {
        return $this->adapter;
    }

    public function handle(): void
    {
        $adapter = app($this->adapter);

        if ($adapter instanceof TransitousAdapter) {
            $adapter->warmInterchanges();
        }
    }
}
