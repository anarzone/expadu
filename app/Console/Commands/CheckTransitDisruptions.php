<?php

namespace App\Console\Commands;

use App\Models\Routine;
use App\Models\User;
use App\Notifications\TransitDisruptionNotification;
use App\Services\DisruptionService;
use App\Support\NotificationThrottle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
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
            ->unique()
            ->values()
            ->all();

        if (empty($allAffectedLines)) {
            $this->info('New disruptions have no affected lines.');

            return self::SUCCESS;
        }

        // Find users with routines that mention affected stop names/lines
        // We notify all users with active routines for now — can refine later
        // by matching stop names to specific lines
        $userIds = Routine::where('is_active', true)
            ->pluck('user_id')
            ->unique();

        // Batch all new disruptions into one notification per user
        $allLines = $newDisruptions->flatMap(fn ($d) => $d['affected_lines'] ?? [])->unique()->values();
        $batchSummary = $newDisruptions->count() === 1
            ? ($newDisruptions->first()['description'] ?? $newDisruptions->first()['title'] ?? 'Transit disruption')
            : $newDisruptions->count().' disruptions affecting lines '.$allLines->take(5)->implode(', ');

        $notifiedCount = 0;

        foreach ($userIds as $userId) {
            $user = User::find($userId);

            if (! $user || ! $user->wantsNotification('transit')) {
                continue;
            }

            // Check throttle — if exceeded, notification still goes to in-app alerts via listener
            if (! NotificationThrottle::canPush($user, 'transit_disruption')) {
                continue;
            }

            $user->notify(new TransitDisruptionNotification([
                'line' => $allLines->take(3)->implode(', '),
                'summary' => $batchSummary,
            ]));

            NotificationThrottle::recordSent($user);
            $notifiedCount++;
        }

        $this->info("Sent {$notifiedCount} disruption notification(s) for ".count($newIds).' new disruption(s).');

        Log::info('Transit disruption notifications sent', [
            'new_disruptions' => count($newIds),
            'notifications_sent' => $notifiedCount,
        ]);

        return self::SUCCESS;
    }
}
