<?php

namespace App\Console\Commands\Bureaucracy;

use App\Services\BuergeramtService;
use App\Services\SmartCjm\SlotAvailabilityService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Live availability checks against the city's Smart CJM booking system,
 * feeding real earliest-appointment times into the Offices grid. `--all`
 * (scheduled) sweeps every bookable service; a single-service run backs the
 * grid's "Check now" button. Both share SlotAvailabilityService's freshness
 * window, and everything is gated behind services.smartcjm.enabled (the
 * robots.txt posture switch).
 */
class CheckSlotsCommand extends Command
{
    protected $signature = 'slots:check
        {service? : Service key from BuergeramtService::SERVICES (defaults to anmeldung)}
        {--all : Refresh every bookable service instead of one}
        {--dry-run : Fetch and print without keeping the cache}';

    protected $description = 'Check real appointment availability across all offices';

    public function handle(SlotAvailabilityService $slotAvailability): int
    {
        if (! $slotAvailability->isEnabled()) {
            $this->error('Live slot checks are disabled. Set SMARTCJM_SLOTS_ENABLED=true to enable.');

            return self::FAILURE;
        }

        if ($this->option('all')) {
            $summary = $slotAvailability->refreshAll();
            foreach ($summary as $service => $count) {
                $this->line($count < 0
                    ? "  <fg=red>✗</> {$service}: failed"
                    : "  <fg=green>✓</> {$service}: {$count} office(s) with availability");
            }
            $this->info('Swept '.count($summary).' service(s).');

            return self::SUCCESS;
        }

        $serviceKey = $this->argument('service') ?? BuergeramtService::DEFAULT_SERVICE;

        try {
            $live = $slotAvailability->refresh($serviceKey, keepCache: ! $this->option('dry-run'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Office', 'Earliest appointment'],
            collect($live['offices'])
                ->sortBy('next_slot')
                ->map(fn (array $data, string $key) => [$key, $data['next_slot']])
                ->values()
                ->all(),
        );
        $this->info(count($live['offices'])." office(s) with availability for {$serviceKey}.");

        return self::SUCCESS;
    }
}
