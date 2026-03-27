<?php

namespace App\Console\Commands;

use App\Models\UserEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AnalyticsSummary extends Command
{
    /**
     * @var string
     */
    protected $signature = 'analytics:summary {--days=7 : Number of days to look back}';

    /**
     * @var string
     */
    protected $description = 'Show analytics summary from the user_events table';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $since = Carbon::now()->subDays($days);

        $this->info("Analytics summary — last {$days} days");
        $this->newLine();

        // Total events
        $totalEvents = UserEvent::where('created_at', '>=', $since)->count();
        $this->line("  Total events: <comment>{$totalEvents}</comment>");

        // Unique users
        $uniqueUsers = UserEvent::where('created_at', '>=', $since)
            ->distinct('user_id')
            ->count('user_id');
        $this->line("  Unique users: <comment>{$uniqueUsers}</comment>");

        $this->newLine();

        // Top event types
        $topTypes = UserEvent::where('created_at', '>=', $since)
            ->selectRaw('event_type, count(*) as total')
            ->groupBy('event_type')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        if ($topTypes->isNotEmpty()) {
            $this->info('Top event types:');
            $this->table(
                ['Event Type', 'Count'],
                $topTypes->map(fn ($row) => [$row->event_type, $row->total])->toArray(),
            );
        }

        // Most active hours
        $activeHours = UserEvent::where('created_at', '>=', $since)
            ->selectRaw('EXTRACT(HOUR FROM created_at) as hour, count(*) as total')
            ->groupBy('hour')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        if ($activeHours->isNotEmpty()) {
            $this->info('Most active hours:');
            $this->table(
                ['Hour (UTC)', 'Count'],
                $activeHours->map(fn ($row) => [str_pad((string) (int) $row->hour, 2, '0', STR_PAD_LEFT).':00', $row->total])->toArray(),
            );
        }

        return self::SUCCESS;
    }
}
