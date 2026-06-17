<?php

namespace App\Jobs;

use App\Services\WeatherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Stale-while-revalidate background refresh for a single weather coordinate.
 * Fired when WeatherService::fetch sees a stale-but-not-fresh cache hit;
 * caller gets stale immediately, this job warms the fresh cache asynchronously.
 */
class RefreshWeather implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(
        public float $lat,
        public float $lng,
    ) {
        // Deployed environments (prod/staging) set QUEUE_CONNECTION=redis and
        // run the worker on `redis --queue=commute,default`, so pinning redis
        // keeps their behaviour identical. Local dev keeps QUEUE_CONNECTION=
        // database and drains it via `composer run dev`; pinning redis there
        // pushes this job onto a queue nothing consumes, so the weather cache
        // never warms and the widget is stuck on "unavailable" forever. Only
        // pin when deployed; locally fall back to the default connection the
        // dev worker actually reads.
        // (Set in the constructor, not as a typed property, because Queueable
        // already declares $connection/$queue and PHP 8.4 rejects a differing
        // redeclaration.)
        if (! app()->isLocal()) {
            $this->onConnection('redis');
        }
        $this->onQueue('default');
    }

    public function handle(WeatherService $weather): void
    {
        $weather->fetchSync($this->lat, $this->lng);
    }

    public function uniqueId(): string
    {
        return round($this->lat, 4).'_'.round($this->lng, 4);
    }

    public function uniqueFor(): int
    {
        return 60;
    }
}
