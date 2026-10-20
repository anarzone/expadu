<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

/**
 * The mock's sample moment, on this machine only. tools/mock-compare sets
 * the plain `mock_clock` cookie (e.g. 2026-09-30T13:10:00+02:00) so a page
 * works out the same day the mock shows; LocalMockClock freezes the clock
 * for that request. Outside the local environment the cookie means nothing:
 * staging and production run APP_ENV=production.
 */
final class MockClock
{
    public const COOKIE = 'mock_clock';

    /** The moment the request asks for, when this machine allows one. */
    public static function at(?Request $request = null): ?CarbonImmutable
    {
        if (! app()->environment('local')) {
            return null;
        }

        $value = ($request ?? request())->cookies->get(self::COOKIE);

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?([+-]\d{2}:\d{2}|Z)$/', $value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** Whether this request runs at the mock's moment (local sample data may answer). */
    public static function active(?Request $request = null): bool
    {
        return self::at($request) !== null;
    }
}
