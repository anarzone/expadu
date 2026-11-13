<?php

namespace App\Transit;

/**
 * Our self-hosted MOTIS v2 instance (VRS GTFS + GTFS-RT + an NRW OSM
 * extract), the primary routing provider. It speaks the same MOTIS engine
 * protocol as the public Transitous instance — so the request shaping,
 * itinerary/leg mapping (including decoded `legGeometry` polylines) and
 * geocoding are all inherited from {@see TransitousAdapter}. The only
 * deltas are the base URL, the plan endpoint version (v1 vs Transitous's
 * v3), how v1 counts `maxTransfers` (rides, not changes) and the `source`
 * stamp.
 *
 * Failure handling (timeouts, circuit breaking, the NRW service-area guard
 * and the failover cascade) lives in {@see FailoverRouteService}; this
 * adapter stays a thin, cache-free protocol client.
 */
class MotisAdapter extends TransitousAdapter
{
    protected function baseUrl(): string
    {
        return rtrim((string) config('services.motis.url'), '/');
    }

    protected function planPath(): string
    {
        return '/api/v1/plan';
    }

    protected function source(): string
    {
        return 'motis';
    }

    /** The v1 plan API counts rides, not changes: one ride is no change (0 answers nothing). */
    protected function directOnly(): array
    {
        return ['maxTransfers' => 1];
    }
}
