<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\TransitDisruptionNotification;
use App\Services\DisruptionService;
use App\Services\UserTransitLinesService;
use App\Support\NotificationThrottle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheckTransitDisruptions extends Command
{
    protected $signature = 'transit:check-disruptions';

    protected $description = 'Check for new transit disruptions and notify affected users';

    public function handle(DisruptionService $disruptionService, UserTransitLinesService $transitLines): int
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

        // Check all onboarded users — match against routines + places + GPS
        $notifiedCount = 0;

        User::whereNotNull('onboarded_at')
            ->chunk(100, function ($users) use ($transitLines, $allAffectedLines, $newDisruptions, &$notifiedCount) {
                foreach ($users as $user) {
                    $relevant = $transitLines->getRelevantLines($user);
                    $matchedLines = $allAffectedLines->intersect($relevant['lines']);

                    if ($matchedLines->isEmpty()) {
                        continue;
                    }

                    if (! $user->wantsNotification('transit')) {
                        continue;
                    }

                    // Find disruptions matching this user's lines and determine severity
                    $userDisruptions = $newDisruptions->filter(function ($d) use ($matchedLines) {
                        return collect($d['affected_lines'] ?? [])->intersect($matchedLines)->isNotEmpty();
                    });

                    // Use critical_disruption type for major/critical disruptions (exempt from quiet hours)
                    $maxSeverity = $userDisruptions->max('severity');
                    $isCritical = in_array($maxSeverity, ['critical', 'major']);
                    $throttleType = $isCritical ? 'critical_disruption' : 'transit_disruption';

                    if (! NotificationThrottle::canPush($user, $throttleType)) {
                        continue;
                    }

                    // Dedup: skip if we already notified this user about these lines recently
                    $lineKey = $matchedLines->sort()->implode(',');
                    $dedupKey = "disruption_notif:{$user->id}:{$lineKey}";
                    if (Cache::has($dedupKey)) {
                        continue;
                    }

                    Cache::put($dedupKey, true, now()->addHours(6));

                    // Build personalized summary with location context
                    $context = $matchedLines
                        ->map(fn ($l) => $relevant['context'][$l] ?? '')
                        ->filter()
                        ->unique()
                        ->first();

                    $summary = $userDisruptions->count() === 1
                        ? ($userDisruptions->first()['description'] ?? $userDisruptions->first()['title'] ?? 'Transit disruption')
                        : $userDisruptions->count().' disruptions affecting lines '.$matchedLines->take(5)->implode(', ');

                    if ($context) {
                        $summary .= " — {$context}";
                    }

                    $user->notify(new TransitDisruptionNotification([
                        'line' => $matchedLines->take(3)->implode(', '),
                        'summary' => $summary,
                    ]));

                    NotificationThrottle::recordSent($user);
                    $notifiedCount++;
                }
            });

        $this->info("Sent {$notifiedCount} disruption notification(s) for ".count($newIds).' new disruption(s).');

        Log::info('Transit disruption notifications sent', [
            'new_disruptions' => count($newIds),
            'notifications_sent' => $notifiedCount,
        ]);

        return self::SUCCESS;
    }
}
