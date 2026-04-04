<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\RhineFloodNotification;
use App\Services\RhineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheckRhineLevel extends Command
{
    protected $signature = 'rhine:check';

    protected $description = 'Check Rhine water level and notify users when it exceeds warning thresholds';

    private const WARNING_CM = 620; // Cologne warning level ~6.20m

    private const HIGH_CM = 750;    // Cologne high water level ~7.50m

    public function handle(RhineService $rhineService): int
    {
        $level = $rhineService->getCurrentLevel();

        if (! $level) {
            $this->info('Could not fetch Rhine level.');

            return self::SUCCESS;
        }

        $cm = $level['level_cm'];
        $status = $level['status'];
        $trend = $level['trend'];

        $this->info("Rhine level: {$cm}cm ({$trend}), status: {$status}");

        // Only alert on warning/high status or when level exceeds thresholds
        if ($cm < self::WARNING_CM && ! in_array($status, ['high', 'warning'])) {
            $this->info('Level normal — no alerts needed.');
            Cache::forget('rhine:last_alert_level');

            return self::SUCCESS;
        }

        // Dedup: don't re-alert if we already alerted at similar level (within 50cm)
        $lastAlertLevel = Cache::get('rhine:last_alert_level', 0);
        if (abs($cm - $lastAlertLevel) < 50 && $lastAlertLevel > 0) {
            $this->info('Already alerted at similar level — skipping.');

            return self::SUCCESS;
        }

        Cache::put('rhine:last_alert_level', $cm, now()->addHours(6));

        // Notify all users who want rhine alerts
        $notifiedCount = 0;
        User::whereNotNull('onboarded_at')->chunk(100, function ($users) use ($cm, $status, $trend, &$notifiedCount) {
            foreach ($users as $user) {
                if (! $user->wantsNotification('rhine')) {
                    continue;
                }

                $user->notify(new RhineFloodNotification($cm, $status, $trend));
                $notifiedCount++;
            }
        });

        $this->info("Notified {$notifiedCount} user(s) about Rhine level {$cm}cm.");

        Log::info('Rhine flood notifications sent', [
            'level_cm' => $cm,
            'status' => $status,
            'users_notified' => $notifiedCount,
        ]);

        return self::SUCCESS;
    }
}
