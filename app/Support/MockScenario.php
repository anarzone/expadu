<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The mock's review situation ("?scenario=" in departures-next), on this
 * machine only. tools/mock-compare sets the plain `mock_scenario` cookie
 * beside `mock_clock` so the sample fixtures (App\Transit\MockSample) answer
 * as the mock's situation does: a lift out, live times paused, a long wait,
 * a cancelled or missed tram. Only those fixtures read it, and only while
 * the mock clock is on; outside the local environment it means nothing.
 */
final class MockScenario
{
    public const COOKIE = 'mock_scenario';

    /** The mock's situations that change what the app's data says. */
    public const KNOWN = ['cancelled', 'missed', 'lift', 'stale', 'longwait'];

    /** The situation this request asks for, when this machine allows one. */
    public static function current(?Request $request = null): ?string
    {
        if (! MockClock::active($request)) {
            return null;
        }

        $value = ($request ?? request())->cookies->get(self::COOKIE);

        return in_array($value, self::KNOWN, true) ? $value : null;
    }

    public static function is(string $scenario, ?Request $request = null): bool
    {
        return self::current($request) === $scenario;
    }
}
