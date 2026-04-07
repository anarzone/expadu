<?php

namespace App\Services\Weather;

interface WeatherProvider
{
    /**
     * Fetch current weather and hourly forecast from the underlying API.
     *
     * Returns null if the fetch fails so the orchestrator can fall back to
     * another provider. All numeric fields are in metric units (°C, km/h, mm).
     *
     * @return array{
     *   current: array{
     *     temperature: float,
     *     feels_like: float|null,
     *     icon: string,
     *     wind_speed: float,
     *     wind_gust: float,
     *     wind_direction: float,
     *     humidity: float|null,
     *     precipitation: float,
     *   },
     *   hourly: list<array{hour: int, precipitation: float}>,
     * }|null
     */
    public function fetch(float $lat, float $lng): ?array;

    /**
     * Short identifier used for logging and metrics.
     */
    public function name(): string;
}
