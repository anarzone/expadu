<?php

namespace App\Console\Commands;

use App\Events\Context\BuergeramtSlotsAvailable;
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

        // BuergeramtEvaluator fans out per-user (push for active SlotMonitor
        // holders, dashboard-only for situation matches) via the ActionBus.
        foreach ($newlyAvailable as $officeId => $slot) {
            $dates = [];
            if (! empty($slot['next_slot'])) {
                $dates[] = (string) $slot['next_slot'];
            }
            event(new BuergeramtSlotsAvailable(
                officeId: (string) $officeId,
                dates: $dates,
            ));
        }

        return self::SUCCESS;
    }
}
