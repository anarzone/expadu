<?php

namespace App\Console\Commands\Bureaucracy;

use App\Services\BuergeramtService;
use App\Services\SmartCjm\SmartCjmClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One live availability check against the city's Smart CJM booking system,
 * feeding real next_slot/slots_today into the Offices grid (which otherwise
 * shows "Check online"). Gated behind services.smartcjm.enabled and NOT
 * scheduled: termine.stadt-koeln.de's robots.txt disallows crawling, so any
 * recurring watcher needs an explicit posture decision first.
 */
class CheckSlotsCommand extends Command
{
    protected $signature = 'slots:check
        {service=anmeldung : Service key from BuergeramtService::SERVICES}
        {--dry-run : Fetch and print availability without writing the cache}';

    protected $description = 'Check real appointment availability for a service across all offices';

    public function handle(SmartCjmClient $client, BuergeramtService $buergeramt): int
    {
        if (! config('services.smartcjm.enabled')) {
            $this->error('Live slot checks are disabled. Set SMARTCJM_SLOTS_ENABLED=true to enable.');

            return self::FAILURE;
        }

        $serviceKey = $this->argument('service');
        $service = BuergeramtService::SERVICES[$serviceKey] ?? null;
        if ($service === null) {
            $this->error("Unknown service '{$serviceKey}'. Known: ".implode(', ', array_keys(BuergeramtService::SERVICES)));

            return self::FAILURE;
        }

        $calendarUrl = BuergeramtService::BOOKING_URLS[$service['category']] ?? null;
        if ($calendarUrl === null || ! Str::contains($calendarUrl, 'termine.stadt-koeln.de')) {
            $this->error("Service '{$serviceKey}' is not booked through the city's appointment system.");

            return self::FAILURE;
        }

        $availability = $client->fetchAvailability($calendarUrl, $service['uid']);
        $offices = $this->aggregateByOffice($availability, $buergeramt);

        $this->table(
            ['Office', 'Next slot', 'Today', 'Total'],
            collect($offices)->map(fn (array $data, string $key) => [
                $key, $data['next_slot'] ?? '—', $data['slots_today'], $data['slots_total'],
            ])->values()->all(),
        );

        $totalSlots = array_sum(array_column($offices, 'slots_total'));
        $this->info("{$totalSlots} free slot(s) for {$service['name']} across ".count($offices).' office(s).');

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        Cache::put('buergeramt_slots_live', [
            'service' => $serviceKey,
            'category' => $service['category'],
            'checked_at' => now()->toIso8601String(),
            'offices' => $offices,
        ], now()->addMinutes(30));

        // Drop the merged directory cache so the fresh data surfaces immediately.
        Cache::forget('buergeramt_slots');

        return self::SUCCESS;
    }

    /**
     * Collapse per-location availability onto OFFICES keys. Multiple booking
     * locations can map to one office (Innenstadt I + II); offered locations
     * without slots still get an entry, so zero means confirmed fully booked.
     *
     * @param  array{locations: list<string>, slots: array<string, list<string>>}  $availability
     * @return array<string, array{next_slot: ?string, slots_today: int, slots_total: int}>
     */
    private function aggregateByOffice(array $availability, BuergeramtService $buergeramt): array
    {
        $labels = array_unique(array_merge($availability['locations'], array_keys($availability['slots'])));

        $offices = [];
        foreach ($labels as $label) {
            $officeKey = $buergeramt->officeKeyForLocation($label);
            if ($officeKey === null) {
                $this->warn("Unmapped booking location: {$label}");

                continue;
            }

            $offices[$officeKey] ??= ['next_slot' => null, 'slots_today' => 0, 'slots_total' => 0];

            foreach ($availability['slots'][$label] ?? [] as $slot) {
                $offices[$officeKey]['slots_total']++;
                if (Carbon::parse($slot)->isToday()) {
                    $offices[$officeKey]['slots_today']++;
                }
                if ($offices[$officeKey]['next_slot'] === null || $slot < $offices[$officeKey]['next_slot']) {
                    $offices[$officeKey]['next_slot'] = $slot;
                }
            }
        }

        return $offices;
    }
}
