<?php

use App\Enums\SpotCategory;

test('picnic rolls up into the parks bucket and is a Places category', function () {
    expect(SpotCategory::Picnic->coarse())->toBe('park');
    expect(SpotCategory::placesFines())->toContain('picnic');
    expect(SpotCategory::finesForCoarse('park'))->toContain('picnic');
    expect(SpotCategory::Picnic->isOutdoor())->toBeTrue();
    expect(SpotCategory::Picnic->label())->toBe('Picnic spot');
    expect(SpotCategory::Picnic->emoji())->toBe('🧺');
});

test('bbq keeps its own grill identity, distinct from picnic', function () {
    expect(SpotCategory::Bbq->coarse())->toBe('park');
    expect(SpotCategory::Bbq->emoji())->not->toBe(SpotCategory::Picnic->emoji());
});

test('finesForSelector expands a coarse bucket to its whole family', function () {
    expect(SpotCategory::finesForSelector('court'))
        ->toEqualCanonicalizing(['basketball', 'tennis', 'table_tennis', 'boules', 'skatepark', 'sports_centre']);
    expect(SpotCategory::finesForSelector('culture'))
        ->toEqualCanonicalizing(['museum', 'gallery', 'attraction', 'zoo']);
});

test('finesForSelector keeps a fine value that heads no coarse family', function () {
    // The blocker: 'basketball' rolls up into 'court', so finesForCoarse returns
    // []. finesForSelector must return the fine value itself, or a solo
    // "basketball" plan is silently widened to complements with no basketball.
    expect(SpotCategory::finesForCoarse('basketball'))->toBe([]);

    foreach (['basketball', 'tennis', 'skatepark', 'library', 'bar', 'cafe'] as $fine) {
        expect(SpotCategory::finesForSelector($fine))->toBe([$fine]);
    }
});
