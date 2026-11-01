<?php

namespace App\Day;

use App\Composer\TravelEstimator;
use App\Models\User;
use App\Services\UserLocationService;
use App\Transit\Dto\GeoPoint;
use Carbon\CarbonImmutable;

/**
 * Whether the person was still where their way starts after the time they
 * had to leave for it, so the card can say "Missed the 13:14". Only while
 * they share their location, and only from their own pings, read and never
 * stored again. A fix indoors can sit still while the person moves, so the
 * card says this can be wrong.
 */
class StillAtStart
{
    /** Pings this close to where the way starts count as not having left. */
    public const RADIUS_METRES = 150.0;

    /** The newest ping comes this long after the leave time: a walk is out of the radius by then. */
    public const AFTER_LEAVE_SECONDS = 180;

    /** ...and is no older than this, so it says where they are now. */
    public const FRESH_SECONDS = 300;

    public function __construct(
        private readonly UserLocationService $locations,
        private readonly TravelEstimator $distance,
    ) {}

    /**
     * @param  GeoPoint  $start  where the way starts (the person's location then)
     * @param  GeoPoint  $board  the stop it boards at: someone there may be waiting, not missing it
     */
    public function since(User $user, GeoPoint $start, GeoPoint $board, CarbonImmutable $leave, CarbonImmutable $now): bool
    {
        if ($user->setting('share_location') !== true) {
            return false;
        }

        $pings = $this->locations->pingsBetween($user, $leave->getTimestamp() + 1, $now->getTimestamp());
        $newest = $pings === [] ? null : max(array_column($pings, 'at'));
        if ($newest === null || $newest < $leave->getTimestamp() + self::AFTER_LEAVE_SECONDS || $newest < $now->getTimestamp() - self::FRESH_SECONDS) {
            return false;
        }

        foreach ($pings as $ping) {
            if ($this->metres($ping, $start) > self::RADIUS_METRES || $this->metres($ping, $board) <= self::RADIUS_METRES) {
                return false;
            }
        }

        return true;
    }

    /** @param array{lat: float, lng: float} $ping */
    private function metres(array $ping, GeoPoint $point): float
    {
        return 1000 * $this->distance->haversineKm($ping['lat'], $ping['lng'], $point->lat, $point->lng);
    }
}
