<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\WeatherAlertNotification;
use App\Services\WeatherService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheckWeatherAlerts extends Command
{
    protected $signature = 'weather:check-alerts';

    protected $description = 'Check weather forecast and alert users about significant weather (rain, storm, extreme temps)';

    public function handle(WeatherService $weatherService): int
    {
        $current = $weatherService->getCurrentWeather();
        $forecast = $weatherService->getForecast();

        if (empty($current) || empty($forecast)) {
            $this->info('Could not fetch weather data.');

            return self::SUCCESS;
        }

        $alerts = [];

        // Check for rain starting
        $rainStart = $forecast['rain_starts'] ?? null;
        if ($rainStart) {
            $alerts[] = [
                'summary' => "Rain expected from {$rainStart}",
                'detail' => 'Consider taking an umbrella or switching to transit.',
                'key' => "rain_{$rainStart}",
            ];
        }

        // Check for extreme cold (< 0°C)
        $temp = $current['temperature'] ?? 15;
        if ($temp < 0) {
            $alerts[] = [
                'summary' => "Freezing temperatures: {$temp}°C",
                'detail' => 'Watch for icy roads and sidewalks. Dress warmly.',
                'key' => 'freeze_'.date('Y-m-d'),
            ];
        }

        // Check for extreme heat (> 33°C)
        if ($temp > 33) {
            $alerts[] = [
                'summary' => "Heat warning: {$temp}°C",
                'detail' => 'Stay hydrated and avoid direct sun. Check on vulnerable neighbors.',
                'key' => 'heat_'.date('Y-m-d'),
            ];
        }

        // Check for high winds (> 60 km/h)
        $windGusts = $current['wind_gusts'] ?? 0;
        if ($windGusts > 60) {
            $alerts[] = [
                'summary' => "Strong wind gusts: {$windGusts} km/h",
                'detail' => 'Cycling may be dangerous. Consider transit.',
                'key' => 'wind_'.date('Y-m-d-H'),
            ];
        }

        if (empty($alerts)) {
            $this->info('No weather alerts needed.');

            return self::SUCCESS;
        }

        $notifiedCount = 0;

        foreach ($alerts as $alert) {
            // Dedup: skip if already sent this alert today
            $dedupKey = "weather_alert:{$alert['key']}";
            if (Cache::has($dedupKey)) {
                continue;
            }

            Cache::put($dedupKey, true, now()->addHours(12));

            User::whereNotNull('onboarded_at')->chunk(100, function ($users) use ($alert, &$notifiedCount) {
                foreach ($users as $user) {
                    if (! $user->wantsNotification('events')) {
                        continue;
                    }

                    $user->notify(new WeatherAlertNotification($alert['summary'], $alert['detail']));
                    $notifiedCount++;
                }
            });
        }

        $this->info("Sent {$notifiedCount} weather alert(s).");
        Log::info('Weather alert notifications sent', ['count' => $notifiedCount, 'alerts' => count($alerts)]);

        return self::SUCCESS;
    }
}
