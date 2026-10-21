<?php

use App\Composer\Candidate;
use App\Composer\PlanScorer;
use App\Composer\ScoringContext;
use App\Composer\TravelEstimator;
use Carbon\CarbonImmutable;

function moodCandidate(string $id, array $vibeTags): Candidate
{
    return new Candidate(
        id: $id, type: 'spot', name: $id, lat: 50.94, lng: 6.95, veedel: 'Ehrenfeld', category: 'cafe',
        outdoor: false, typicalDurationMin: 60, costTier: 'low', opensAt: null, closesAt: null, vibeTags: $vibeTags,
    );
}

function moodScore(Candidate $candidate, array $moods): float
{
    $context = new ScoringContext(rainExpected: false, preferredAreas: ['Ehrenfeld'], moods: $moods);

    return (new PlanScorer(new TravelEstimator))->score($candidate, [], CarbonImmutable::parse('2026-10-24 15:00', 'Europe/Berlin'), 50.94, 6.95, $context);
}

it('ranks a place whose verified tags meet the mood above one without them', function () {
    $quiet = moodCandidate('spot:1', ['quiet']);
    $plain = moodCandidate('spot:2', []);

    expect(moodScore($quiet, ['quiet']))->toBeGreaterThan(moodScore($plain, ['quiet']))
        ->and(moodScore($quiet, []))->toBe(moodScore($plain, []));
});

it('gives no mood credit for tags that do not meet the mood', function () {
    expect(moodScore(moodCandidate('spot:1', ['lively']), ['quiet']))->toBe(moodScore(moodCandidate('spot:2', []), ['quiet']));
});
