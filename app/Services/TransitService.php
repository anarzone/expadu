<?php

namespace App\Services;

class TransitService
{
    /**
     * Get live departures for a stop. Mock data until VRS API integration.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDepartures(string $stop = 'Ehrenfeld'): array
    {
        return [
            ['line' => '9', 'type' => 'tram', 'destination' => 'Königsforst', 'platform' => '1', 'minutes' => 3, 'status' => 'on_time'],
            ['line' => '13', 'type' => 'tram', 'destination' => 'Sülzgürtel', 'platform' => '2', 'minutes' => 7, 'status' => 'on_time'],
            ['line' => '1', 'type' => 'tram', 'destination' => 'Bensberg', 'platform' => '1', 'minutes' => 11, 'status' => 'delayed', 'delay' => 4],
            ['line' => '142', 'type' => 'bus', 'destination' => 'Bocklemünd', 'platform' => 'A', 'minutes' => 5, 'status' => 'on_time'],
            ['line' => 'S12', 'type' => 'sbahn', 'destination' => 'Düren', 'platform' => '3', 'minutes' => 14, 'status' => 'on_time'],
            ['line' => '9', 'type' => 'tram', 'destination' => 'Königsforst', 'platform' => '1', 'minutes' => 18, 'status' => 'on_time'],
        ];
    }

    /**
     * Get route options between two points. Mock data.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRoutes(string $from, string $to): array
    {
        return [
            [
                'mode' => 'bike',
                'emoji' => '🚲',
                'label' => 'Bike via Innere Kanalstr.',
                'detail' => 'Safe lane · No disruptions',
                'duration' => 22,
                'status' => 'ok',
                'recommended' => true,
            ],
            [
                'mode' => 'transit',
                'emoji' => '🚇',
                'line' => '9',
                'label' => 'Tram Line 9 → Neumarkt',
                'detail' => 'Next in 4 min · Every 8 min',
                'duration' => 19,
                'status' => 'ok',
            ],
            [
                'mode' => 'transit',
                'emoji' => '🚇',
                'line' => '1',
                'label' => 'Tram Line 1 → Köln Messe',
                'detail' => '+4 min delay · Match crowd tonight',
                'duration' => 34,
                'status' => 'warning',
            ],
        ];
    }

    /**
     * Get active disruptions. Mock data.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDisruptions(): array
    {
        return [
            [
                'id' => 1,
                'severity' => 'warning',
                'lines' => ['1'],
                'title' => 'Line 1 delays due to FC Köln match',
                'description' => 'Expect overcrowding from 19:00. Use Line 9 via Ehrenfeld as alternative.',
                'started_at' => now()->subHours(2)->toIso8601String(),
                'estimated_end' => now()->addHours(3)->toIso8601String(),
            ],
            [
                'id' => 2,
                'severity' => 'info',
                'lines' => ['S12', 'S19'],
                'title' => 'Platform change at Köln Hbf',
                'description' => 'S12 and S19 depart from platform 5 instead of platform 3 until further notice.',
                'started_at' => now()->subDays(1)->toIso8601String(),
                'estimated_end' => null,
            ],
        ];
    }
}
