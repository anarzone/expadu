<?php

namespace App\Console\Commands;

use App\Models\SlotMonitor;
use App\Notifications\BuergeramtSlotNotification;
use App\Services\BuergeramtService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheckBuergeramtSlots extends Command
{
    /**
     * @var string
     */
    protected $signature = 'buergeramt:check';

    /**
     * @var string
     */
    protected $description = 'Check Cologne Buergeramt offices for available appointment slots';

    public function handle(BuergeramtService $service): int
    {
        $this->info('Checking Buergeramt appointment slots...');

        $currentSlots = $service->checkSlots();
        $previousSlots = Cache::get('buergeramt:slots', []);

        // Find newly available offices (were not available before, now are)
        $newlyAvailable = [];

        foreach ($currentSlots as $officeId => $slot) {
            if ($slot['status'] !== 'available') {
                continue;
            }

            $previousStatus = $previousSlots[$officeId]['status'] ?? 'booked';

            if ($previousStatus !== 'available') {
                $newlyAvailable[$officeId] = $slot;
            }
        }

        // Cache the current state for the next run
        Cache::put('buergeramt:slots', $currentSlots, now()->addMinutes(10));

        // Log results
        $availableCount = collect($currentSlots)->where('status', 'available')->count();
        $this->info("  {$availableCount} office(s) with available slots");
        $this->info('  '.count($newlyAvailable).' newly available since last check');

        Log::info('Buergeramt slot check completed', [
            'available_offices' => $availableCount,
            'newly_available' => count($newlyAvailable),
        ]);

        // Notify users who are monitoring newly available offices
        if (count($newlyAvailable) > 0) {
            $this->notifyUsers($newlyAvailable);
        }

        return self::SUCCESS;
    }

    /**
     * Notify users who have active monitors for the newly available offices.
     *
     * @param  array<string, array{name: string, address: string, status: string, next_slot: ?string, slots_today: int}>  $newlyAvailable
     */
    protected function notifyUsers(array $newlyAvailable): void
    {
        $officeIds = array_keys($newlyAvailable);

        $monitors = SlotMonitor::where('is_active', true)
            ->whereIn('office_id', $officeIds)
            ->with('user')
            ->get();

        $notifiedCount = 0;

        foreach ($monitors as $monitor) {
            // Skip if already notified within the last 30 minutes
            if ($monitor->notified_at && $monitor->notified_at->isAfter(now()->subMinutes(30))) {
                continue;
            }

            // Respect user notification preferences
            if (! $monitor->user->wantsNotification('burgeramt')) {
                continue;
            }

            // Build the available slots specific to this user's monitored office
            $userSlots = collect($newlyAvailable)
                ->only($monitor->office_id)
                ->all();

            if (empty($userSlots)) {
                continue;
            }

            $monitor->user->notify(new BuergeramtSlotNotification($userSlots));
            $monitor->update(['notified_at' => now()]);
            $notifiedCount++;
        }

        $this->info("  Notified {$notifiedCount} user(s)");

        Log::info('Buergeramt slot notifications sent', [
            'notified_users' => $notifiedCount,
            'offices' => $officeIds,
        ]);
    }
}
