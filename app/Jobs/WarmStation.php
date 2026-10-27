<?php

namespace App\Jobs;

use App\Transit\NearbyStation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reads a station Travel doesn't keep yet (NearbyStation::warm), off the
 * request: Today sends the stop's name and re-reads in place once it's kept.
 * One per stop at a time, so a burst of views queues one read.
 */
class WarmStation implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public string $stop)
    {
        // Pinned as RefreshWeather is: redis where deployed (the worker runs
        // `redis --queue=commute,default`), the default connection locally,
        // which the dev worker reads.
        if (! app()->isLocal()) {
            $this->onConnection('redis');
        }
        $this->onQueue('default');
    }

    public function handle(NearbyStation $nearby): void
    {
        $nearby->warm($this->stop);
    }

    public function uniqueId(): string
    {
        return mb_strtolower($this->stop);
    }

    /** Long enough for one read; a stop still cold after it can be queued again. */
    public function uniqueFor(): int
    {
        return 30;
    }
}
