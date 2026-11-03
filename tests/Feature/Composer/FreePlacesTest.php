<?php

use App\Composer\Assistant\AssistantTools;
use App\Composer\Candidate;
use Carbon\CarbonImmutable;

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

test('another day\'s hours are said with its date, closed included, and never a today word', function () {
    $sunday = CarbonImmutable::parse('2026-11-08', 'Europe/Berlin');
    $place = fn (array $hours): Candidate => new Candidate(...['id' => 'spot:2', 'type' => 'spot', 'name' => 'Rewe', 'lat' => 50.9, 'lng' => 6.9, 'veedel' => 'Sülz',
        'category' => 'supermarket', 'outdoor' => false, 'typicalDurationMin' => 30, 'costTier' => 'unknown', 'opensAt' => null, 'closesAt' => null,
        'placeFacts' => ['hours' => ['status' => 'known']], ...$hours]);

    expect(AssistantTools::placeText($place(['closedToday' => true]), $sunday, false, onDay: $sunday))->toContain('Closed on Sunday 8 November, by its listed hours.')
        ->and(AssistantTools::placeText($place(['opensAt' => $sunday->setTime(8, 0), 'closesAt' => $sunday->setTime(20, 0)]), $sunday, false, onDay: $sunday))
        ->toContain('On Sunday 8 November: open 08:00–20:00.')->not->toContain('Open now')
        ->and(AssistantTools::placeText($place(['opensAt' => $sunday->setTime(8, 0), 'closesAt' => $sunday->setTime(20, 0)]), $sunday->setTime(19, 0), false, onDay: $sunday))
        ->toContain('Open at 19:00 on Sunday 8 November, until 20:00.');
});
