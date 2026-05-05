<?php

namespace App\Events\Context;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WeatherChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $hourlyForecast
     * @param  list<array<string, mixed>>  $alerts
     */
    public function __construct(
        public string $condition,
        public array $hourlyForecast,
        public array $alerts,
        public float $lat,
        public float $lng,
    ) {}
}
