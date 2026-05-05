<?php

namespace App\Console\Commands;

use App\Events\Context\MarketClosureDetected;
use App\Events\Context\WeatherChanged;
use App\Models\User;
use App\Notifications\MarketClosureNotification;
use App\Notifications\WeatherAlertNotification;
use App\Services\GermanHolidayService;
use App\Services\WeatherService;
use App\Support\NotificationThrottle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheckWeatherAlerts extends Command
{
    protected $signature = 'weather:check-alerts';

    protected $description = 'Check weather forecast and alert users about significant weather (rain, storm, extreme temps)';

    public function handle(WeatherService $weatherService): int
    {
        // Market closure warning — only between 12:00-16:00, no weather data needed
        $closureCount = 0;
        $hour = now()->hour;
        if ($hour >= 12 && $hour <= 16) {
            $closureCount = $this->sendMarketClosureWarning();
            if ($closureCount > 0) {
                $this->info("Sent {$closureCount} market closure warning(s).");
            }
        }

        $current = $weatherService->getCurrentWeather();
        $forecast = $weatherService->getForecast();

        if (empty($current) || empty($forecast)) {
            if ($closureCount === 0) {
                $this->info('No weather alerts needed.');
            }

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
        $windGusts = $current['wind_gust'] ?? 0;
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

        // Dual-emit: a single WeatherChanged event with all new alerts, fanned out by WeatherEvaluator.
        $emittedAlerts = [];
        foreach ($alerts as $alert) {
            $dedupKey = "weather_alert:{$alert['key']}";
            if (Cache::has($dedupKey)) {
                continue;
            }
            $emittedAlerts[] = [
                'id' => $alert['key'] ?? null,
                'severity' => $alert['severity'] ?? 'minor',
                'title' => $alert['summary'] ?? '',
                'description' => $alert['detail'] ?? '',
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

        foreach ($alerts as $alert) {
            // Dedup: skip if already sent this alert today
            $dedupKey = "weather_alert:{$alert['key']}";
            if (Cache::has($dedupKey)) {
                continue;
            }

            Cache::put($dedupKey, true, now()->addHours(12));

            User::whereNotNull('onboarded_at')->chunk(100, function ($users) use ($alert, &$notifiedCount) {
                foreach ($users as $user) {
                    if (! $user->wantsNotification('weather')) {
                        continue;
                    }

                    if (! NotificationThrottle::canPush($user, 'weather_commute')) {
                        continue;
                    }

                    $user->notify(new WeatherAlertNotification($alert['summary'], $alert['detail']));
                    NotificationThrottle::recordSent($user);
                    $notifiedCount++;
                }
            });
        }

        $this->info("Sent {$notifiedCount} weather alert(s).");
        Log::info('Weather alert notifications sent', ['count' => $notifiedCount, 'alerts' => count($alerts)]);

        return self::SUCCESS;
    }

    private function sendMarketClosureWarning(): int
    {
        $holidayService = app(GermanHolidayService::class);

        if (! $holidayService->isShopsClosedTomorrow()) {
            return 0;
        }

        $tomorrow = now()->addDay();
        $holidayName = $holidayService->getHolidayName($tomorrow);

        $dedupKey = 'market_closure_notif:'.$tomorrow->format('Y-m-d');
        if (Cache::has($dedupKey)) {
            return 0;
        }

        Cache::put($dedupKey, true, now()->addDays(2));

        // Dual-emit: MarketEvaluator fans out per-user.
        event(new MarketClosureDetected(
            marketId: 'all',
            day: $tomorrow->format('Y-m-d'),
            reason: $holidayName ?? 'Sunday',
        ));

        $count = 0;

        User::whereNotNull('onboarded_at')->chunk(100, function ($users) use ($holidayName, &$count) {
            foreach ($users as $user) {
                if (! $user->wantsNotification('events')) {
                    continue;
                }

                if (! NotificationThrottle::canPush($user, 'market_closure')) {
                    continue;
                }

                $reason = $holidayName ?? 'Sunday';
                $user->notify(new MarketClosureNotification($reason, $holidayName));
                NotificationThrottle::recordSent($user);
                $count++;
            }
        });

        return $count;
    }
}
