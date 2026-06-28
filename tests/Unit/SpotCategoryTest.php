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
