<?php

namespace App\Console\Commands\Bureaucracy;

use App\Services\SmartCjm\SlotAvailabilityService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * One live availability check against the city's Smart CJM booking system,
 * feeding real next_slot/slots_today into the Offices grid (which otherwise
 * shows "Check online"). Scheduled during opening-ish hours and also
 * triggered by the grid's refresh button; both paths share
 * SlotAvailabilityService's freshness window, and everything is gated
 * behind services.smartcjm.enabled (the robots.txt posture switch).
 */
class CheckSlotsCommand extends Command
{
    protected $signature = 'slots:check
        {service=anmeldung : Service key from BuergeramtService::SERVICES}
        {--dry-run : Fetch and print availability without keeping the cache}';

    protected $description = 'Check real appointment availability for a service across all offices';

    public function handle(SlotAvailabilityService $slotAvailability): int
    {
        if (! $slotAvailability->isEnabled()) {
            $this->error('Live slot checks are disabled. Set SMARTCJM_SLOTS_ENABLED=true to enable.');

            return self::FAILURE;
        }

        try {
            $live = $slotAvailability->refresh($this->argument('service'), keepCache: ! $this->option('dry-run'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Office', 'Next slot', 'Today', 'Total'],
            collect($live['offices'])->map(fn (array $data, string $key) => [
                $key, $data['next_slot'] ?? '—', $data['slots_today'], $data['slots_total'],
            ])->values()->all(),
        );

        $totalSlots = array_sum(array_column($live['offices'], 'slots_total'));
        $this->info("{$totalSlots} free slot(s) for {$this->argument('service')} across ".count($live['offices']).' office(s).');

        return self::SUCCESS;
    }
}
