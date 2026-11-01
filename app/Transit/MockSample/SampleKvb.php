<?php

namespace App\Transit\MockSample;

use App\Services\KvbApiService;
use App\Support\MockClock;
use App\Support\MockScenario;

/**
 * KVB's lift feed while the local mock clock is on: the mock's sample has
 * no lift record ("Lifts unknown"), so the lifts aren't known, and nothing
 * is fetched. In the mock's "a lift is out of order" situation
 * (MockScenario `lift`) one of Neumarkt's lifts is out, the one to the
 * 3 · 4 · 16 · 18 platforms the mock hides. Every request without the mock
 * clock reads the real feed. Bound only in local (TravelServiceProvider).
 */
final class SampleKvb extends KvbApiService
{
    public function knownElevatorDisruptions(): ?array
    {
        if (! MockClock::active()) {
            return parent::knownElevatorDisruptions();
        }

        return MockScenario::is('lift') ? [[
            'id' => 'sample-neumarkt-lift',
            'label' => 'Neumarkt: lift to the 3 · 4 · 16 · 18 platforms',
            // Neumarkt is KVB station 2 (points 211…222).
            'stop_area' => '2',
            'info' => 'Out of order (sample)',
            'lat' => 50.93542,
            'lng' => 6.9491,
            'timestamp' => '',
        ]] : null;
    }
}
