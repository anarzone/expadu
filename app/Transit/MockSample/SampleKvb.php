<?php

namespace App\Transit\MockSample;

use App\Services\KvbApiService;
use App\Support\MockClock;

/**
 * KVB's lift feed while the local mock clock is on: the mock's sample has
 * no lift record ("Lifts unknown"), so the lifts aren't known, and nothing
 * is fetched. Every request without the mock clock reads the real feed.
 * Bound only in local (TravelServiceProvider).
 */
final class SampleKvb extends KvbApiService
{
    public function knownElevatorDisruptions(): ?array
    {
        return MockClock::active() ? null : parent::knownElevatorDisruptions();
    }
}
