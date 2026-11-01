<?php

use App\Composer\Candidate;
use App\Composer\Constraints;
use App\Composer\FeasibilityFilter;
use Carbon\CarbonImmutable;

function hardCandidate(string $id, string $type, string $category, ?array $wheelchair = null): Candidate
{
    return new Candidate(
        id: $id, type: $type, name: $id, lat: 50.94, lng: 6.95, veedel: 'Ehrenfeld', category: $category,
        outdoor: false, typicalDurationMin: 60, costTier: 'free', opensAt: null, closesAt: null,
        fixedStart: $type === 'event' ? CarbonImmutable::parse('2026-10-24 15:00', 'Europe/Berlin') : null,
        placeFacts: $wheelchair === null ? [] : ['practical' => ['wheelchair' => $wheelchair]],
    );
}

function hardWindow(array $changes = []): Constraints
{
    return (new Constraints(CarbonImmutable::parse('2026-10-24 13:00', 'Europe/Berlin'), CarbonImmutable::parse('2026-10-24 19:00', 'Europe/Berlin')))->with($changes);
}

it('keeps only verified step-free places when the request needs it', function () {
    $pool = [
        hardCandidate('spot:1', 'spot', 'cafe', ['value' => 'yes', 'status' => 'known']),
        hardCandidate('spot:2', 'spot', 'cafe', ['value' => 'limited', 'status' => 'known']),
        hardCandidate('spot:3', 'spot', 'cafe', ['value' => 'yes', 'status' => 'conflicting']),
        hardCandidate('spot:4', 'spot', 'cafe'),
        hardCandidate('event:5', 'event', 'culture'),
    ];

    $kept = app(FeasibilityFilter::class)->filter(hardWindow(['needs' => ['step_free']]), $pool);

    expect(array_column($kept, 'id'))->toBe(['spot:1']);
});

it('drops the place types a request rules out', function () {
    $pool = [hardCandidate('spot:1', 'spot', 'bar'), hardCandidate('spot:2', 'spot', 'cafe')];

    expect(array_column(app(FeasibilityFilter::class)->filter(hardWindow(['avoid' => ['bar']]), $pool), 'id'))->toBe(['spot:2']);
});

it('keeps events of any kind when the request asks for events', function () {
    $pool = [hardCandidate('event:5', 'event', 'culture'), hardCandidate('spot:2', 'spot', 'cafe')];

    expect(array_column(app(FeasibilityFilter::class)->filter(hardWindow(['categories' => ['event']]), $pool), 'id'))->toBe(['event:5']);
});
