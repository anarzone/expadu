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

    /**
     * Pin to the redis connection — prod's queue:work runs on
     * `redis --queue=commute,default`. Without this the job dispatches
     * to the app-default (database) and never gets processed.
     */
    public $connection = 'redis';

    public $queue = 'default';

    public function __construct(
        public float $lat,
        public float $lng,
    ) {}

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
