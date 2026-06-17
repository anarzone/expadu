<?php

use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Journey;
use App\Transit\Dto\Leg;
use App\Transit\Dto\Place;
use App\Transit\FareAdvisor;
use Carbon\CarbonImmutable;

function fareLeg(string $mode, int $durationMin, ?int $stops, array $from, array $to): Leg
{
    $t = CarbonImmutable::parse('2026-06-17 12:00');

    return new Leg(
        mode: $mode,
        from: new Place('A', new GeoPoint($from[0], $from[1]), null),
        to: new Place('B', new GeoPoint($to[0], $to[1]), null),
        departAt: $t,
        arriveAt: $t->addMinutes($durationMin),
        durationMin: $durationMin,
        lineName: null,
        headsign: null,
        polyline: null,
        stopsCount: $stops,
    );
}

function fareJourney(Leg ...$legs): Journey
{
    $t = CarbonImmutable::parse('2026-06-17 12:00');

    return new Journey(legs: $legs, departAt: $t, arriveAt: $t->addHour(), durationMin: 30, transfers: 0);
}

$koeln = [50.9413, 6.9583];
$koelnSouth = [50.9365, 6.9350];
$bonn = [50.7374, 7.0982];
$leverkusen = [51.03, 6.98];

test('a longer ride within Köln bills at level 1b (€4.00)', function () use ($koeln, $koelnSouth) {
    $journey = fareJourney(fareLeg('tram', 25, 6, $koeln, $koelnSouth));

    $advice = (new FareAdvisor)->advise($journey, 'Köln', 'Köln', hasDeutschlandticket: false);

    expect($advice->preisstufe)->toBe('1b');
    expect($advice->priceEur)->toBe(4.00);
    expect($advice->estimated)->toBeFalse();
    expect($advice->eezyCapEur)->toBe(4.00);
    expect($advice->coveredByDeutschlandticket)->toBeFalse();
});

test('a short hop bills at Kurzstrecke (€2.90)', function () use ($koeln, $koelnSouth) {
    $journey = fareJourney(fareLeg('tram', 8, 3, $koeln, $koelnSouth));

    $advice = (new FareAdvisor)->advise($journey, 'Köln', 'Köln', hasDeutschlandticket: false);

    expect($advice->preisstufe)->toBe('K');
    expect($advice->priceEur)->toBe(2.90);
    expect($advice->label)->toContain('Kurzstrecke');
});

test('an S-Bahn (rail) leg voids the Kurzstrecke', function () use ($koeln, $koelnSouth) {
    // Short by stops/time, but rail (S-Bahn/RB/RE) is excluded from Kurzstrecke.
    $journey = fareJourney(fareLeg('rail', 10, 2, $koeln, $koelnSouth));

    $advice = (new FareAdvisor)->advise($journey, 'Köln', 'Köln', hasDeutschlandticket: false);

    expect($advice->preisstufe)->toBe('1b');
});

test('a held Deutschlandticket covers the trip', function () use ($koeln, $bonn) {
    $journey = fareJourney(fareLeg('rail', 40, 8, $koeln, $bonn));

    $advice = (new FareAdvisor)->advise($journey, 'Köln', 'Bonn', hasDeutschlandticket: true);

    expect($advice->coveredByDeutschlandticket)->toBeTrue();
    expect($advice->priceEur)->toBeNull();
    expect($advice->preisstufe)->toBeNull();
    expect($advice->label)->toContain('Deutschlandticket');
});

test('a far cross-municipality trip is estimated at level 3 with eezy as the net', function () use ($koeln, $bonn) {
    // 6+ stops so it isn't a Kurzstrecke; Köln→Bonn is ~23 km air-line (> 12).
    $journey = fareJourney(fareLeg('rail', 35, 10, $koeln, $bonn));

    $advice = (new FareAdvisor)->advise($journey, 'Köln', 'Bonn', hasDeutschlandticket: false);

    expect($advice->preisstufe)->toBe('3');
    expect($advice->priceEur)->toBe(13.90);
    expect($advice->estimated)->toBeTrue();
    expect($advice->eezyCapEur)->toBe(13.90);
});

test('a nearer cross-municipality trip is estimated at level 2', function () use ($koeln, $leverkusen) {
    $journey = fareJourney(fareLeg('tram', 18, 9, $koeln, $leverkusen));

    $advice = (new FareAdvisor)->advise($journey, 'Köln', 'Leverkusen', hasDeutschlandticket: false);

    expect($advice->preisstufe)->toBe('2');
    expect($advice->priceEur)->toBe(5.50);
    expect($advice->estimated)->toBeTrue();
});

test('advice carries how-to-buy channels including eezy', function () use ($koeln, $koelnSouth) {
    $journey = fareJourney(fareLeg('tram', 25, 6, $koeln, $koelnSouth));

    $advice = (new FareAdvisor)->advise($journey, 'Köln', 'Köln', hasDeutschlandticket: false);

    $labels = array_map(fn ($c) => $c['label'], $advice->howToBuy);
    expect($labels)->toContain('eezy.nrw');
});
