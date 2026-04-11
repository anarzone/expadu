<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\User;
use App\Services\WeatherService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Automated health check for the notification pipeline.
 * Returns exit code 1 if any critical check fails.
 *
 * Run locally:  php artisan notification:health-check
 * Production:   docker exec <container> php artisan notification:health-check
 * Schedule:     hourly via routes/console.php
 */
class NotificationHealthCheck extends Command
{
    protected $signature = 'notification:health-check';

    protected $description = 'Check notification pipeline health: weather API, Redis, dedup, subtypes, location pings';

    private bool $hasCritical = false;

    public function handle(WeatherService $weatherService): int
    {
        // 1. Weather API
        $weather = $weatherService->getCurrentWeather();
        if ($weather['condition'] === 'Unavailable') {
            $this->critical('Weather API', 'All 4 providers failed');
        } else {
            $this->checkPass('Weather API', "{$weather['condition']} {$weather['temperature']}°C");
        }

        // 2. Redis
        try {
            Redis::ping();
            $this->checkPass('Redis', 'Connected');
        } catch (\Throwable $e) {
            $this->critical('Redis', 'Connection failed: '.$e->getMessage());
        }

        // 3. Duplicate alerts (same user + title in last 24h)
        $dupes = Alert::where('created_at', '>=', now()->subDay())
            ->selectRaw('user_id, title, count(*) as cnt')
            ->groupBy('user_id', 'title')
            ->havingRaw('count(*) > 1')
            ->get();

        if ($dupes->isNotEmpty()) {
            $examples = $dupes->take(3)->map(fn ($d) => "\"{$d->title}\" x{$d->cnt}")->implode(', ');
            $this->checkWarn('Duplicate alerts', "{$dupes->count()} found: {$examples}");
        } else {
            $this->checkPass('Duplicate alerts', '0 found in last 24h');
        }

        // 4. Null subtypes in recent alerts
        $nullSubtypes = Alert::whereNull('subtype')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        if ($nullSubtypes > 0) {
            $this->checkWarn('Alert subtypes', "{$nullSubtypes} null subtypes in last 7 days");
        } else {
            $this->checkPass('Alert subtypes', '0 null in last 7 days');
        }

        // 5. Location pings
        $onboardedUsers = User::whereNotNull('onboarded_at')->get();
        $withRecentPing = 0;

        foreach ($onboardedUsers as $user) {
            try {
                $latest = Redis::zrevrange("location_history:{$user->id}", 0, 0, 'WITHSCORES');
                if (! empty($latest)) {
                    $score = array_values($latest)[0] ?? 0;
                    if ($score > now()->subDay()->timestamp) {
                        $withRecentPing++;
                    }
                }
            } catch (\Throwable) {
                break;
            }
        }

        $total = $onboardedUsers->count();
        if ($total > 0 && $withRecentPing === 0) {
            $this->checkWarn('Location pings', "0/{$total} users have a ping in 24h");
        } else {
            $this->checkPass('Location pings', "{$withRecentPing}/{$total} users active in 24h");
        }

        // 6. Throttle saturation
        $saturated = 0;
        $today = now()->format('Y-m-d');

        foreach ($onboardedUsers as $user) {
            try {
                $dayCount = (int) Redis::get("notif_throttle:day:{$user->id}:{$today}");
                if ($dayCount >= 5) {
                    $saturated++;
                }
            } catch (\Throwable) {
                break;
            }
        }

        if ($total > 0 && $saturated > 0) {
            $this->checkWarn('Throttle', "{$saturated}/{$total} users hit daily cap");
        } else {
            $this->checkPass('Throttle', "0/{$total} users saturated");
        }

        // Summary
        $this->newLine();
        if ($this->hasCritical) {
            Log::error('Notification health check FAILED', ['command' => 'notification:health-check']);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function checkPass(string $check, string $detail): void
    {
        $this->line("<fg=green>[PASS]</> {$check}: {$detail}");
    }

    private function checkWarn(string $check, string $detail): void
    {
        $this->line("<fg=yellow>[WARN]</> {$check}: {$detail}");
    }

    private function critical(string $check, string $detail): void
    {
        $this->line("<fg=red>[FAIL]</> {$check}: {$detail}");
        $this->hasCritical = true;
    }
}
