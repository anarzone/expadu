<?php

namespace App\Services;

/**
 * KVB line → brand colour: the Stadtbahn lines' official colours (from the
 * GTFS feed's route_color), buses a neutral grey, S-Bahn its green.
 */
final class KvbLineColors
{
    private const DEFAULT = '#1A4CD4';

    private const BUS = '#4A4A4A';

    /** DB S-Bahn green — the S6/S11/S19… identity riders recognise. */
    private const SBAHN = '#008D4B';

    /** Regionalbahn / RegionalExpress (RB, RE, RRX) — a neutral rail slate. */
    private const REGIONAL = '#455A6B';

    /**
     * KVB's own line colours: the VRS GTFS feed's route_color for each Stadtbahn line, as on
     * KVB's network map (owner, 7 Nov 2026: the official colours are the base; the app draws
     * them as gradients).
     */
    private const TRAM = [
        '1' => '#E0071C',
        '3' => '#F29DC2',
        '4' => '#EA71A5',
        '5' => '#A69CC8',
        '7' => '#F08D51',
        '9' => '#F09086',
        '12' => '#98BF1D',
        '13' => '#A98567',
        '15' => '#5AAC31',
        '16' => '#06ADA5',
        '17' => '#85D0F5',
        '18' => '#1D92D1',
    ];

    public static function for(string $line, ?string $type = null): string
    {
        $line = trim($line);

        if (isset(self::TRAM[$line])) {
            return self::TRAM[$line];
        }

        // S-Bahn keeps its green identity (S6, S11, S19 — "S" + a digit, so
        // SchnellBus "SB40" doesn't match). Regional trains get a rail slate.
        if (preg_match('/^S\d/', $line)) {
            return self::SBAHN;
        }

        if (preg_match('/^(RB|RE|RRX|MRB)/i', $line)) {
            return self::REGIONAL;
        }

        // Night buses (N…) and numbered bus lines.
        if ($type === 'bus' || str_starts_with($line, 'N') || preg_match('/^\d{3}$/', $line)) {
            return self::BUS;
        }

        return self::DEFAULT;
    }
}
