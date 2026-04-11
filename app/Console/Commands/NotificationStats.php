<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\User;
use App\Models\UserEvent;
use App\Services\WeatherService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * Interactive CLI dashboard for notification system stats.
 * Shows real data from database and Redis.
 *
 * Run: php artisan notification:stats
 *      php artisan notification:stats --days=1
 */
class NotificationStats extends Command
{
    protected $signature = 'notification:stats {--days=7 : Number of days to look back}';

    protected $description = 'Show notification system stats: alerts, delivery, throttle, tracking, weather';

    public function handle(WeatherService $weatherService): int
    {
        $days = (int) $this->option('days');
        $since = now()->subDays($days);

        $this->info("Notification Stats — last {$days} days");
        $this->line(str_repeat('─', 50));

        $this->alertsByType($since);
        $this->alertsPerDay($since);
        $this->readDismissRatios($since);
        $this->duplicateAlerts($since);
        $this->throttleState();
        $this->userEventStats($since);
        $this->locationPings();
        $this->weatherStatus($weatherService);

        return self::SUCCESS;
    }

    private function alertsByType($since): void
    {
        $this->newLine();
        $this->info('Alerts by type/subtype');

        $rows = Alert::where('created_at', '>=', $since)
            ->selectRaw('type, subtype, count(*) as total')
            ->groupBy('type', 'subtype')
            ->orderByDesc('total')
            ->get();

        if ($rows->isEmpty()) {
            $this->line('  No alerts in this period.');

            return;
        }

        $this->table(
            ['Type', 'Subtype', 'Count'],
            $rows->map(fn ($r) => [$r->type->value ?? $r->type, $r->subtype ?? '(null)', $r->total])
        );
    }

    private function alertsPerDay($since): void
    {
        $this->newLine();
        $this->info('Alerts per day');

        $rows = Alert::where('created_at', '>=', $since)
            ->selectRaw('date(created_at) as day, count(*) as total, count(read_at) as read_cnt, count(dismissed_at) as dismissed_cnt')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        if ($rows->isEmpty()) {
            $this->line('  No alerts in this period.');

            return;
        }

        $this->table(
            ['Date', 'Total', 'Read', 'Dismissed'],
            $rows->map(fn ($r) => [$r->day, $r->total, $r->read_cnt, $r->dismissed_cnt])
        );
    }

    private function readDismissRatios($since): void
    {
        $total = Alert::where('created_at', '>=', $since)->count();
        $read = Alert::where('created_at', '>=', $since)->whereNotNull('read_at')->count();
        $dismissed = Alert::where('created_at', '>=', $since)->whereNotNull('dismissed_at')->count();

        if ($total === 0) {
            return;
        }

        $this->newLine();
        $this->info('Engagement');
        $this->table(
            ['Metric', 'Count', 'Rate'],
            [
                ['Total alerts', $total, '100%'],
                ['Read', $read, round($read / $total * 100).'%'],
                ['Dismissed', $dismissed, round($dismissed / $total * 100).'%'],
                ['Unread', $total - $read, round(($total - $read) / $total * 100).'%'],
            ]
        );
    }

    private function duplicateAlerts($since): void
    {
        $dupes = Alert::where('created_at', '>=', $since)
            ->selectRaw('user_id, title, count(*) as cnt')
            ->groupBy('user_id', 'title')
            ->havingRaw('count(*) > 1')
            ->orderByDesc('cnt')
            ->limit(10)
            ->get();

        if ($dupes->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->warn('Duplicate alerts (same user + title)');
        $this->table(
            ['User ID', 'Title', 'Count'],
            $dupes->map(fn ($d) => [$d->user_id, mb_substr($d->title, 0, 40), $d->cnt])
        );
    }

    private function throttleState(): void
    {
        $this->newLine();
        $this->info('Throttle state (today)');

        $users = User::whereNotNull('onboarded_at')->get();
        $today = now()->format('Y-m-d');
        $hour = now()->format('Y-m-d-H');
        $rows = [];

        foreach ($users as $user) {
            try {
                $dayCount = (int) Redis::get("notif_throttle:day:{$user->id}:{$today}");
                $hourCount = (int) Redis::get("notif_throttle:hour:{$user->id}:{$hour}");
                $last = Redis::get("notif_throttle:last:{$user->id}");
                $lastAgo = $last ? now()->timestamp - (int) $last.'s ago' : 'never';

                $rows[] = [
                    $user->id,
                    $user->name,
                    "{$dayCount}/5",
                    "{$hourCount}/2",
                    $lastAgo,
                ];
            } catch (\Throwable) {
                $rows[] = [$user->id, $user->name, '?', '?', 'Redis unavailable'];
                break;
            }
        }

        $this->table(['ID', 'Name', 'Day', 'Hour', 'Last Sent'], $rows);
    }

    private function userEventStats($since): void
    {
        $this->newLine();
        $this->info('User event tracking');

        $rows = UserEvent::where('created_at', '>=', $since)
            ->selectRaw('event_type, count(*) as total')
            ->groupBy('event_type')
            ->orderByDesc('total')
            ->get();

        if ($rows->isEmpty()) {
            $this->line('  No events tracked in this period.');

            return;
        }

        $this->table(
            ['Event Type', 'Count'],
            $rows->map(fn ($r) => [$r->event_type, $r->total])
        );
    }

    private function locationPings(): void
    {
        $this->newLine();
        $this->info('Location pings (Redis)');

        $users = User::whereNotNull('onboarded_at')->get();
        $rows = [];

        foreach ($users as $user) {
            try {
                $count = Redis::zcard("location_history:{$user->id}");
                $latest = Redis::zrevrange("location_history:{$user->id}", 0, 0, 'WITHSCORES');
                $lastPing = 'never';

                if (! empty($latest)) {
                    $score = (int) array_values($latest)[0];
                    $lastPing = now()->subSeconds(now()->timestamp - $score)->diffForHumans();
                }

                $rows[] = [$user->id, $user->name, $count, $lastPing];
            } catch (\Throwable) {
                $rows[] = [$user->id, $user->name, '?', 'Redis unavailable'];
                break;
            }
        }

        $this->table(['ID', 'Name', 'Total Pings', 'Last Ping'], $rows);
    }

    private function weatherStatus(WeatherService $weatherService): void
    {
        $this->newLine();
        $this->info('Weather API');

        $weather = $weatherService->getCurrentWeather();
        $this->table(
            ['Field', 'Value'],
            [
                ['Condition', $weather['condition']],
                ['Temperature', "{$weather['temperature']}°C"],
                ['Wind gust', "{$weather['wind_gust']} km/h"],
                ['Humidity', "{$weather['humidity']}%"],
            ]
        );
    }
}
