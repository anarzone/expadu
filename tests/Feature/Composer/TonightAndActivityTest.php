<?php

use App\Composer\CategoryHours;
use App\Models\Spot;
use App\Places\PlaceFacts;
use Carbon\CarbonImmutable;

it('keeps viewpoints to daylight, like parks', function () {
    [$opens, $closes] = CategoryHours::defaults('viewpoint', CarbonImmutable::parse('2026-10-21', 'Europe/Berlin'));

    expect($opens)->not->toBeNull()->and($closes->hour)->toBeLessThan(22);
});

it('lets OSM evidence, not a manual review, qualify a pitch for an activity', function () {
    $evidence = ['category' => 'pitch', 'is_active' => true, 'is_recommendable' => false, 'lat' => 51.04, 'lng' => 6.93];
    $public = Spot::factory()->create([...$evidence, 'name' => 'Bolzplatz', 'tags' => ['sport' => 'soccer', 'leisure' => 'pitch']]);
    $private = Spot::factory()->create([...$evidence, 'name' => 'Bolzplatz', 'tags' => ['sport' => 'soccer', 'access' => 'private']]);
    $members = Spot::factory()->create([...$evidence, 'name' => 'Vereinsplatz', 'tags' => ['sport' => 'soccer', 'membership' => 'required']]);
    $noSport = Spot::factory()->create([...$evidence, 'name' => 'Bolzplatz', 'tags' => ['leisure' => 'pitch']]);
    Spot::query()->whereKey([$public->id, $private->id, $members->id, $noSport->id])->update(['public_access_allowed' => true]);

    $ids = Spot::query()->where(fn ($q) => app(PlaceFacts::class)->activityQualified($q))->pluck('id')->all();

    expect($ids)->toContain($public->id)
        ->not->toContain($private->id)
        ->not->toContain($members->id)
        ->not->toContain($noSport->id);
});
