<?php

namespace App\Services\SmartCjm;

use App\Services\BuergeramtService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Runs one availability check for a bookable service and keeps the result
 * in the buergeramt_slots_live cache that BuergeramtService::checkSlots
 * overlays onto the office directory. Shared by the scheduled slots:check
 * run and the user-facing refresh button; a freshness window makes the two
 * (and button-mashing) collapse into at most one probe per window, keeping
 * the request volume against the city bounded.
 */
class SlotAvailabilityService
{
    public const CACHE_KEY = 'buergeramt_slots_live';

    /**
     * Results younger than this are reused instead of re-fetched.
     */
    private const FRESH_SECONDS = 180;

    public function __construct(
        private SmartCjmClient $client,
        private BuergeramtService $buergeramt,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('services.smartcjm.enabled');
    }

    /**
     * Availability metadata for the frontend: whether live checks are on
     * and when the last one ran.
     *
     * @return array{enabled: bool, checked_at: ?string, service: ?string}
     */
    public function meta(): array
    {
        $live = Cache::get(self::CACHE_KEY);

        return [
            'enabled' => $this->isEnabled(),
            'checked_at' => $live['checked_at'] ?? null,
            'service' => $live['service'] ?? null,
        ];
    }

    /**
     * Check availability for a service and cache it keyed by office. A
     * still-fresh cached result for the same service is returned as-is;
     * $keepCache=false (dry runs) fetches without touching the cache.
     *
     * @return array{service: string, category: string, checked_at: string, offices: array<string, array{next_slot: ?string, slots_today: int, slots_total: int}>}
     */
    public function refresh(string $serviceKey, bool $keepCache = true): array
    {
        $service = BuergeramtService::SERVICES[$serviceKey] ?? null;
        if ($service === null) {
            throw new InvalidArgumentException("Unknown service '{$serviceKey}'. Known: ".implode(', ', array_keys(BuergeramtService::SERVICES)));
        }

        $calendarUrl = BuergeramtService::BOOKING_URLS[$service['category']] ?? null;
        if ($calendarUrl === null || ! Str::contains($calendarUrl, 'termine.stadt-koeln.de')) {
            throw new InvalidArgumentException("Service '{$serviceKey}' is not booked through the city's appointment system.");
        }

        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && ($cached['service'] ?? null) === $serviceKey
            && Carbon::parse($cached['checked_at'])->gt(now()->subSeconds(self::FRESH_SECONDS))) {
            return $cached;
        }

        $availability = $this->client->fetchAvailability($calendarUrl, $service['uid']);

        $live = [
            'service' => $serviceKey,
            'category' => $service['category'],
            'checked_at' => now()->toIso8601String(),
            'offices' => $this->aggregateByOffice($availability),
        ];

        if ($keepCache) {
            Cache::put(self::CACHE_KEY, $live, now()->addMinutes(30));
            // Drop the merged directory cache so the fresh data surfaces immediately.
            Cache::forget('buergeramt_slots');
        }

        return $live;
    }

    /**
     * Collapse per-location availability onto OFFICES keys. Multiple booking
     * locations can map to one office (Innenstadt I + II); offered locations
     * without slots still get an entry, so zero means confirmed fully booked.
     *
     * @param  array{locations: list<string>, slots: array<string, list<string>>}  $availability
     * @return array<string, array{next_slot: ?string, slots_today: int, slots_total: int}>
     */
    private function aggregateByOffice(array $availability): array
    {
        $labels = array_unique(array_merge($availability['locations'], array_keys($availability['slots'])));

        $offices = [];
        foreach ($labels as $label) {
            $officeKey = $this->buergeramt->officeKeyForLocation($label);
            if ($officeKey === null) {
                Log::warning("SmartCJM booking location has no office mapping: {$label}");

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
