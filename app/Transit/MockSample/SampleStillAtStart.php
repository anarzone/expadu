<?php

namespace App\Transit\MockSample;

use App\Day\StillAtStart;
use App\Models\User;
use App\Support\MockClock;
use App\Support\MockScenario;
use App\Transit\Dto\GeoPoint;
use Carbon\CarbonImmutable;

/**
 * Where the person was after their leave time, while the local mock clock is
 * on: in the mock's "You missed your tram" (MockScenario `missed`) they
 * stayed at the start, as their pings would say once the leave time is three
 * minutes gone, so the 13:14 is missed. It stands in for the pings only: the
 * person must still share their location, and no ping is read or written.
 * Otherwise nobody stayed put, and every request without the mock clock
 * reads the real pings. Bound only in local (TravelServiceProvider).
 */
final class SampleStillAtStart extends StillAtStart
{
    public function since(User $user, GeoPoint $start, GeoPoint $board, CarbonImmutable $leave, CarbonImmutable $now): bool
    {
        if (! MockClock::active()) {
            return parent::since($user, $start, $board, $leave, $now);
        }

        return MockScenario::is('missed') && $user->setting('share_location') === true
            && $now->getTimestamp() >= $leave->getTimestamp() + self::AFTER_LEAVE_SECONDS;
    }
}
