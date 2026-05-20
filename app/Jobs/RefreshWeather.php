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
        // Pin to the redis connection — prod's queue:work runs on
        // `redis --queue=commute,default`. Without this the job dispatches
        // to the app-default (database) and never gets processed.
        // Set in constructor (not as class property) because the Queueable
        // trait already declares these without defaults, and PHP 8.4 rejects
        // a redeclaration whose definition differs from the trait's.
        $this->onConnection('redis');
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
