<?php

namespace App\Console\Commands;

use App\Events\Context\MarketClosureDetected;
use App\Events\Context\WeatherChanged;
use App\Services\GermanHolidayService;
use App\Services\WeatherService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheckWeatherAlerts extends Command
{
    protected $signature = 'weather:check-alerts';

    protected $description = 'Check weather forecast and emit context events for significant weather (rain, storm, extreme temps)';

    public function handle(WeatherService $weatherService): int
    {
        // Market closure warning — only between 12:00-16:00, no weather data needed
        $hour = now()->hour;
        if ($hour >= 12 && $hour <= 16) {
            $this->emitMarketClosureWarning();
        }

        $current = $weatherService->getCurrentWeather();
        $forecast = $weatherService->getForecast();

        if (empty($current) || empty($forecast)) {
            $this->info('No weather alerts needed.');

            return self::SUCCESS;
        }

        $alerts = [];

        // Check for rain starting.
        // Identity is the DAY, not the start time: the forecast's rain-start
        // drifts across refreshes (16:00 at 08:00, 18:00 by 10:00), and keying on
        // it minted a fresh alert each time that never superseded the last — two
        // "Rain expected from …" cards for one afternoon of rain. A day key makes
        // a later run supersede the earlier one (same dedup key + same actionKey).
        // `kind` splits weather into two needs: an *advisory* (rain — you can
        // mitigate by umbrella/rerouting, so it only earns a Today tile when it
        // threatens an actual plan/commute) versus a *hazard* (ice/heat/wind — an
        // environmental danger shown unconditionally). `from` carries the rain
        // start so the fusion step can test it against the user's day.
        $rainStart = $forecast['rain_starts'] ?? null;
        if ($rainStart) {
            $alerts[] = [
                'summary' => "Rain expected from {$rainStart}",
                'detail' => 'Consider taking an umbrella or switching to transit.',
                'key' => 'rain_'.now()->format('Y-m-d'),
                'kind' => 'advisory',
                'from' => $rainStart,
            ];
        }

        // Check for extreme cold (< 0°C)
        $temp = $current['temperature'] ?? 15;
        if ($temp < 0) {
            $alerts[] = [
                'summary' => "Freezing temperatures: {$temp}°C",
                'detail' => 'Watch for icy roads and sidewalks. Dress warmly.',
                'key' => 'freeze_'.date('Y-m-d'),
                'kind' => 'hazard',
            ];
        }

        // Check for extreme heat (> 33°C)
        if ($temp > 33) {
            $alerts[] = [
                'summary' => "Heat warning: {$temp}°C",
                'detail' => 'Stay hydrated and avoid direct sun. Check on vulnerable neighbors.',
                'key' => 'heat_'.date('Y-m-d'),
                'kind' => 'hazard',
            ];
        }

        // Check for high winds (> 60 km/h)
        $windGusts = $current['wind_gust'] ?? 0;
        if ($windGusts > 60) {
            $alerts[] = [
                'summary' => "Strong wind gusts: {$windGusts} km/h",
                'detail' => 'Cycling may be dangerous. Consider transit.',
                'key' => 'wind_'.date('Y-m-d-H'),
                'kind' => 'hazard',
            ];
        }

        if (empty($alerts)) {
            $this->info('No weather alerts needed.');

            return self::SUCCESS;
        }

        // Per-user matching and push delivery happen in WeatherEvaluator
        // via the ActionBus.
        $emittedAlerts = [];
        foreach ($alerts as $alert) {
            $dedupKey = "weather_alert:{$alert['key']}";
            if (Cache::has($dedupKey)) {
                continue;
            }

            Cache::put($dedupKey, true, now()->addHours(12));
            $emittedAlerts[] = [
                'id' => $alert['key'] ?? null,
                'severity' => $alert['severity'] ?? 'minor',
                'title' => $alert['summary'] ?? '',
                'description' => $alert['detail'] ?? '',
                'kind' => $alert['kind'] ?? 'advisory',
                'from' => $alert['from'] ?? null,
            ];
        }

        if (! empty($emittedAlerts)) {
            event(new WeatherChanged(
                condition: 'alert',
                hourlyForecast: [],
                alerts: $emittedAlerts,
                lat: 50.9375,
                lng: 6.9603,
            ));
        }

        $this->info('Emitted '.count($emittedAlerts).' weather alert(s).');
        Log::info('Weather alert events emitted', ['count' => count($emittedAlerts)]);

        return self::SUCCESS;
    }

    private function emitMarketClosureWarning(): void
    {
        $holidayService = app(GermanHolidayService::class);

        if (! $holidayService->isShopsClosedTomorrow()) {
            return;
        }

        $tomorrow = now()->addDay();
        $holidayName = $holidayService->getHolidayName($tomorrow);

        $dedupKey = 'market_closure_notif:'.$tomorrow->format('Y-m-d');
        if (Cache::has($dedupKey)) {
            return;
        }

        Cache::put($dedupKey, true, now()->addDays(2));

        // MarketEvaluator fans out per-user.
        event(new MarketClosureDetected(
            marketId: 'all',
            day: $tomorrow->format('Y-m-d'),
            reason: $holidayName ?? 'Sunday',
        ));

        $this->info('Emitted market closure warning for '.$tomorrow->format('Y-m-d').'.');
    }
}
