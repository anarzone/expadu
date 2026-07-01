<?php

namespace App\Transit;

use App\Enums\TransportMode;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;

/**
 * Turns a user's transport-mode preference into per-destination travel minutes.
 *
 * The card time ALWAYS reflects the selected mode, so it can never read as a
 * different one: walk shows the real walking time (even a long one) rather than
 * quietly borrowing the bike figure. Bike and transit both use the bike matrix —
 * the one-to-many matrix can't route transit per destination, so bike is the
 * at-a-glance proxy (exact transit times come from a full plan in take-me-there).
 * An unset preference defaults to walk, which is what the From control shows.
 */
class TravelTimes
{
    public function __construct(private readonly RouteService $routes) {}

    /**
     * Per-destination minutes in the SAME ORDER as $destinations; null where the
     * destination is unreachable in the chosen mode.
     *
     * @param  list<GeoPoint>  $destinations
     * @return list<int|null>
     */
    public function minutes(?TransportMode $mode, GeoPoint $origin, array $destinations): array
    {
        if ($destinations === []) {
            return [];
        }

        // Bike and transit both read the bike matrix (transit isn't matrixable
        // per destination). Walk — and the unset default — show the real walking
        // time, so a card never shows another mode's figure under a walk label.
        $profile = ($mode === TransportMode::Bike || $mode === TransportMode::Transit)
            ? 'BIKE'
            : 'WALK';

        return $this->routes->travelMatrix($origin, $destinations, $profile);
    }
}
