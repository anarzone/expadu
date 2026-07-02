<?php

namespace App\Services\SmartCjm;

use App\Services\BuergeramtService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Fetches and caches the soonest appointment per office, per service, from
 * the city's Smart CJM booking system. One cache entry per service key
 * feeds BuergeramtService::checkSlots(); the scheduled `slots:check --all`
 * refreshes the whole pollable set and the Offices grid's "Check now"
 * button refreshes a single service on demand. A freshness window makes the
 * two collapse into at most one probe per service per window, keeping the
 * request volume against the city bounded.
 */
class SlotAvailabilityService
{
    /**
     * Results younger than this are reused instead of re-fetched.
     */
    private const FRESH_SECONDS = 180;

    /**
     * Per-service cache lifetime — comfortably longer than the poll cadence.
     */
    private const TTL_MINUTES = 180;

    public function __construct(
        private SmartCjmClient $client,
        private BuergeramtService $buergeramt,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('services.smartcjm.enabled');
    }

    public static function cacheKey(string $serviceKey): string
    {
        return "smartcjm.slots.{$serviceKey}";
    }

    /**
     * The services this system can check: every configured service booked
     * through termine.stadt-koeln.de (Bürgeramt + KFZ). Finanzämter use
     * Elster, so they are excluded.
     *
     * @return list<string>
     */
    public function pollableServices(): array
    {
        return collect(BuergeramtService::SERVICES)
            ->filter(fn (array $s) => Str::contains(BuergeramtService::BOOKING_URLS[$s['category']] ?? '', 'termine.stadt-koeln.de'))
            ->keys()
            ->all();
    }

    /**
     * Cached availability for one service, or null if it has never been
     * checked (or the entry has expired).
     *
     * @return array{service: string, category: string, checked_at: string, offices: array<string, array{next_slot: string, booking_url: string, duration: int}>}|null
     */
    public function availabilityFor(string $serviceKey): ?array
    {
        $cached = Cache::get(self::cacheKey($serviceKey));

        return is_array($cached) ? $cached : null;
    }

    /**
     * Availability metadata for the frontend: whether live checks are on and
     * when the given service was last checked.
     *
     * @return array{enabled: bool, checked_at: ?string}
     */
    public function meta(string $serviceKey): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'checked_at' => $this->availabilityFor($serviceKey)['checked_at'] ?? null,
        ];
    }

    /**
     * Refresh every pollable service. Used by the scheduled watcher.
     *
     * @return array<string, int> Service key => office count with availability.
     */
    public function refreshAll(): array
    {
        $summary = [];
        foreach ($this->pollableServices() as $serviceKey) {
            try {
                $live = $this->refresh($serviceKey);
                $summary[$serviceKey] = count($live['offices']);
            } catch (\Throwable $e) {
                report($e);
                $summary[$serviceKey] = -1;
            }
        }

        return $summary;
    }

    /**
     * Fetch the soonest appointment per office for a service and cache it. A
     * still-fresh cached result is returned as-is; $keepCache=false (dry
     * runs) fetches without touching the cache.
     *
     * @return array{service: string, category: string, checked_at: string, offices: array<string, array{next_slot: string, booking_url: string, duration: int}>}
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

        if ($keepCache) {
            $cached = $this->availabilityFor($serviceKey);
            if ($cached !== null && Carbon::parse($cached['checked_at'])->gt(now()->subSeconds(self::FRESH_SECONDS))) {
                return $cached;
            }
        }

        $earliest = $this->client->fetchEarliest($calendarUrl, $service['uid']);

        $live = [
            'service' => $serviceKey,
            'category' => $service['category'],
            'checked_at' => now()->toIso8601String(),
            'offices' => $this->aggregateByOffice($earliest),
        ];

        if ($keepCache) {
            Cache::put(self::cacheKey($serviceKey), $live, now()->addMinutes(self::TTL_MINUTES));
            // Drop the merged directory cache so fresh data surfaces at once.
            Cache::forget('buergeramt_slots');
        }

        return $live;
    }

    /**
     * Collapse per-location earliest slots onto OFFICES keys, keeping the
     * soonest per office (Innenstadt I + II merge into one office).
     *
     * @param  list<array{office: string, unit_uid: string, datetime: string, duration: int, booking_url: string}>  $earliest
     * @return array<string, array{next_slot: string, booking_url: string, duration: int}>
     */
    private function aggregateByOffice(array $earliest): array
    {
        $offices = [];
        foreach ($earliest as $slot) {
            $officeKey = $this->buergeramt->officeKeyForLocation($slot['office']);
            if ($officeKey === null) {
                Log::warning("SmartCJM booking location has no office mapping: {$slot['office']}");

                continue;
            }

            $existing = $offices[$officeKey] ?? null;
            if ($existing === null || $slot['datetime'] < $existing['next_slot']) {
                $offices[$officeKey] = [
                    'next_slot' => $slot['datetime'],
                    'booking_url' => $slot['booking_url'],
                    'duration' => $slot['duration'],
                ];
            }
        }

        return $offices;
    }
}
