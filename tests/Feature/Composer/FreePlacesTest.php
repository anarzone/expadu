<?php

use App\Composer\Assistant\AssistantTools;
use App\Composer\Candidate;

/*
 * Free and public (owner's call through the coordinator, 24 Oct 2026): a place is free only where
 * a source lists no fee, public only where its data says so, and of one with neither listed,
 * nothing is said of either: never more than the places session's public_use holds.
 */
function freePitch(array $use): Candidate
{
    return new Candidate(id: 'spot:1', type: 'spot', name: 'Bolzplatz Hans-Wild-Straße', lat: 50.95, lng: 6.91, veedel: 'Neuehrenfeld',
        category: 'pitch', outdoor: true, typicalDurationMin: 60, costTier: 'unknown', opensAt: null, closesAt: null,
        placeFacts: ['public_use' => $use]);
}

test('a place is said free, public or neither as its public_use lists it, whatever its name', function () {
    $line = fn (array $use): string => AssistantTools::placeText(freePitch($use), free: true);

    expect($line(['value' => 'yes', 'free' => 'yes', 'basis' => 'no_fee', 'source_url' => null]))->toContain('Free to use (listed).')
        ->and($line(['value' => 'yes', 'free' => 'unknown', 'basis' => 'osm_access', 'source_url' => null]))->toContain('Public, no fee listed.')
        ->and($line(['value' => 'unknown', 'free' => 'unknown', 'basis' => null, 'source_url' => null]))->toContain('Fee not listed.')
        ->not->toContain('Public')->not->toContain('Free to use');
});
