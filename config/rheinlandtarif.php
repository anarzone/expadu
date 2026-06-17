<?php

/*
 * Rheinlandtarif — the VRS + AVV merged tariff (live 2026-06-01). All
 * EinzelTicket (single-trip, adult) prices in euros. Pinned against the
 * official KVB table; treat `verified_at` like a bureaucracy verified_at —
 * re-check on the source before changing figures (fares = trust).
 *
 * Source: https://www.kvb.koeln/abos-und-tickets/preise-und-preisstufen.html
 */

return [
    'source' => 'https://www.kvb.koeln/abos-und-tickets/preise-und-preisstufen.html',
    'verified_at' => '2026-06-17',

    // EinzelTicket per Preisstufe (fare level).
    'single' => [
        'K' => 2.90,   // Kurzstrecke — short hop
        '1a' => 3.50,  // within one municipality (not Köln/Bonn/Aachen)
        '1b' => 4.00,  // within Köln / Bonn / Aachen — the city level
        '2' => 5.50,   // into the surrounding region
        '3' => 13.90,  // whole Rheinland
    ],

    // Municipalities billed at level 1b for an internal trip (else 1a).
    'city_municipalities' => ['Köln', 'Koeln', 'Cologne', 'Bonn', 'Aachen'],

    // Kurzstrecke: entry + at most 4 stops, 20 min; NOT valid on
    // S-Bahn/RB/RE/Schnellbus (our normalized 'rail' mode). Temporary tariff.
    'kurzstrecke' => [
        'max_stops' => 4,
        'max_minutes' => 20,
        'excluded_modes' => ['rail'],
        'ends' => '2028-05-31',
    ],

    // Covers all local + regional transit (KVB/VRS); NOT IC/ICE.
    'deutschlandticket' => [
        'monthly_eur' => 63.00,
        'jobticket_approx_eur' => 45.00,
    ],

    // eezy.nrw — check-in/out air-line fare; per-trip cap = the Preisstufe's
    // EinzelTicket, monthly cap then free. Often cheapest for occasional riders.
    'eezy' => [
        'monthly_cap_eur' => 63.00,
    ],

    // Air-line km threshold for the cross-municipality 2-vs-3 estimate. We
    // have no official Tarifgebiet polygons (VRS GTFS carries no fares), so
    // cross-municipality levels are flagged `estimated` and eezy is offered
    // as the "never more than" safety net.
    'cross_municipality_level3_km' => 12.0,

    'how_to_buy' => [
        ['label' => 'KVB app', 'url' => 'https://www.kvb.koeln/'],
        ['label' => 'Rheinlandtarif (VRS)', 'url' => 'https://www.vrs.de/'],
        ['label' => 'eezy.nrw', 'url' => 'https://eezy.nrw/'],
    ],
];
