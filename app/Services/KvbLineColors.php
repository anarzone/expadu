<?php

namespace App\Services;

/**
 * KVB line → brand colour. GTFS `route_color` is authoritative but the
 * static feed isn't imported here yet, so departures come back with a
 * default blue. Until then this map gives trams their familiar colours and
 * buses a neutral grey; once GTFS is imported, route_color wins and this is
 * only a fallback for lines it doesn't cover.
 */
final class KvbLineColors
{
    private const DEFAULT = '#1A4CD4';

    private const BUS = '#4A4A4A';

    /** Approximate KVB tram line colours — refined by GTFS route_color later. */
    private const TRAM = [
        '1' => '#E2001A',
        '3' => '#C8027E',
        '4' => '#A01E5A',
        '5' => '#0089CB',
        '7' => '#009A45',
        '9' => '#E2001A',
        '12' => '#95C11F',
        '13' => '#F18800',
        '15' => '#E2001A',
        '16' => '#0089CB',
        '17' => '#0089CB',
        '18' => '#0089CB',
    ];

    public static function for(string $line, ?string $type = null): string
    {
        $line = trim($line);

        if (isset(self::TRAM[$line])) {
            return self::TRAM[$line];
        }

        // Night buses (N…) and numbered bus lines.
        if ($type === 'bus' || str_starts_with($line, 'N') || preg_match('/^\d{3}$/', $line)) {
            return self::BUS;
        }

        return self::DEFAULT;
    }
}
