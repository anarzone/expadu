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

    /**
     * Honest at-a-glance options for Places cards. Transit has no matrix API,
     * so it must not borrow a cycling time. With no saved preference, use the
     * fastest real direct route returned by the walking/cycling matrices.
     *
     * @param  list<GeoPoint>  $destinations
     * @return list<array{minutes: int|null, mode: string}>
     */
    public function placeOptions(?TransportMode $mode, GeoPoint $origin, array $destinations): array
    {
        if ($destinations === []) {
            return [];
        }

        if ($mode === TransportMode::Transit) {
            return array_fill(0, count($destinations), [
                'minutes' => null,
                'mode' => TransportMode::Transit->value,
            ]);
        }

        if ($mode !== null) {
            $minutes = $this->routes->travelMatrix($origin, $destinations, strtoupper($mode->value));

            return array_map(
                fn (?int $value): array => ['minutes' => $value, 'mode' => $mode->value],
                $minutes,
            );
        }

        $walk = $this->routes->travelMatrix($origin, $destinations, 'WALK');
        $bike = $this->routes->travelMatrix($origin, $destinations, 'BIKE');

        return array_map(function (?int $walkMinutes, ?int $bikeMinutes): array {
            if ($walkMinutes === null && $bikeMinutes === null) {
                return ['minutes' => null, 'mode' => TransportMode::Bike->value];
            }

            if ($bikeMinutes !== null && ($walkMinutes === null || $bikeMinutes < $walkMinutes)) {
                return ['minutes' => $bikeMinutes, 'mode' => TransportMode::Bike->value];
            }

            return ['minutes' => $walkMinutes, 'mode' => TransportMode::Walk->value];
        }, $walk, $bike);
    }
}
