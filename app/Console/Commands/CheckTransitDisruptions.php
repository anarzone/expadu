<?php

namespace App\Console\Commands;

use App\Models\Routine;
use App\Models\User;
use App\Notifications\TransitDisruptionNotification;
use App\Services\DisruptionService;
use App\Support\NotificationThrottle;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckTransitDisruptions extends Command
{
    protected $signature = 'transit:check-disruptions';

    protected $description = 'Check for new transit disruptions and notify affected users';

    public function handle(DisruptionService $disruptionService): int
    {
        $disruptions = $disruptionService->getLineDisruptions();

        if (empty($disruptions)) {
            $this->info('No disruptions found.');

            return self::SUCCESS;
        }

        // Build current disruption IDs
        $currentIds = collect($disruptions)->pluck('id')->filter()->all();
        $seenIds = Cache::get('disruption:seen_ids', []);

        // Find new disruptions
        $newIds = array_diff($currentIds, $seenIds);

        if (empty($newIds)) {
            $this->info('No new disruptions.');

            return self::SUCCESS;
        }

        // Cache current IDs for next run
        Cache::put('disruption:seen_ids', $currentIds, now()->addHours(6));

        // Get new disruptions with their affected lines
        $newDisruptions = collect($disruptions)
            ->filter(fn ($d) => in_array($d['id'] ?? null, $newIds));

        // Collect all affected lines across new disruptions
        $allAffectedLines = $newDisruptions
            ->flatMap(fn ($d) => $d['affected_lines'] ?? [])
            ->map(fn ($l) => (string) $l)
            ->unique()
            ->values();

        if ($allAffectedLines->isEmpty()) {
            $this->info('New disruptions have no affected lines.');

            return self::SUCCESS;
        }

        // Get active routines and match against affected lines per user
        $routines = Routine::where('is_active', true)->with('user')->get();
        $byStop = $routines->groupBy(fn ($r) => $r->from_stop.'|||'.$r->to_stop);

        /** @var array<int, Collection<int, string>> */
        $userAffectedLines = [];

        foreach ($byStop as $stopKey => $stopRoutines) {
            [$fromStop, $toStop] = explode('|||', $stopKey);

            // Get lines serving both from and to stops
            $linesAtFrom = $this->getLinesAtStop($fromStop);
            $linesAtTo = $toStop ? $this->getLinesAtStop($toStop) : collect();
            $linesForRoutine = $linesAtFrom->merge($linesAtTo)->unique();

            // Intersect with disrupted lines
            $matchedLines = $allAffectedLines->intersect($linesForRoutine);

            if ($matchedLines->isEmpty()) {
                continue;
            }

            foreach ($stopRoutines as $routine) {
                $userId = $routine->user_id;
                if (! isset($userAffectedLines[$userId])) {
                    $userAffectedLines[$userId] = collect();
                }
                $userAffectedLines[$userId] = $userAffectedLines[$userId]->merge($matchedLines)->unique()->values();
            }
        }

        if (empty($userAffectedLines)) {
            $this->info('No users affected by new disruptions.');

            return self::SUCCESS;
        }

        $notifiedCount = 0;

        foreach ($userAffectedLines as $userId => $lines) {
            $user = User::find($userId);

            if (! $user || ! $user->wantsNotification('transit')) {
                continue;
            }

            if (! NotificationThrottle::canPush($user, 'transit_disruption')) {
                continue;
            }

            // Build personalized summary showing only the user's affected lines
            $userDisruptions = $newDisruptions->filter(function ($d) use ($lines) {
                return collect($d['affected_lines'] ?? [])->intersect($lines)->isNotEmpty();
            });

            $summary = $userDisruptions->count() === 1
                ? ($userDisruptions->first()['description'] ?? $userDisruptions->first()['title'] ?? 'Transit disruption')
                : $userDisruptions->count().' disruptions affecting lines '.$lines->take(5)->implode(', ');

            $user->notify(new TransitDisruptionNotification([
                'line' => $lines->take(3)->implode(', '),
                'summary' => $summary,
            ]));

            NotificationThrottle::recordSent($user);
            $notifiedCount++;
        }

        $this->info("Sent {$notifiedCount} disruption notification(s) for ".count($newIds).' new disruption(s).');

        Log::info('Transit disruption notifications sent', [
            'new_disruptions' => count($newIds),
            'affected_users' => count($userAffectedLines),
            'notifications_sent' => $notifiedCount,
        ]);

        return self::SUCCESS;
    }

    /**
     * Get transit line names that serve a given stop via GTFS static data.
     *
     * @return Collection<int, string>
     */
    private function getLinesAtStop(string $stopName): Collection
    {
        return Cache::remember("lines_at_stop:{$stopName}", 3600, function () use ($stopName) {
            return DB::table('gtfs_stops')
                ->join('gtfs_stop_times', 'gtfs_stops.stop_id', '=', 'gtfs_stop_times.stop_id')
                ->join('gtfs_trips', 'gtfs_stop_times.trip_id', '=', 'gtfs_trips.trip_id')
                ->join('gtfs_routes', 'gtfs_trips.route_id', '=', 'gtfs_routes.route_id')
                ->where('gtfs_stops.stop_name', 'ILIKE', "%{$stopName}%")
                ->where('gtfs_stops.location_type', 0)
                ->whereNotNull('gtfs_routes.route_short_name')
                ->distinct()
                ->pluck('gtfs_routes.route_short_name');
        });
    }
}
