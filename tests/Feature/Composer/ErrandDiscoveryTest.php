<?php

use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Models\Spot;

function errandWindow(array $categories): Constraints
{
    return Constraints::fromArray([
        'window_start' => '2026-09-28T14:00:00+02:00',
        'window_end' => '2026-09-28T18:00:00+02:00',
        'categories' => $categories, 'radius_km' => 2,
    ]);
}

test('a plan nobody asked errands for leaves out pharmacies and supermarkets, and a request that names one brings it in', function () {
    $museum = Spot::factory()->create(['name' => 'Museum Ludwig', 'category' => 'museum', 'lat' => 50.9409, 'lng' => 6.9604]);
    $pharmacy = Spot::factory()->create(['name' => 'Dom-Apotheke', 'category' => 'pharmacy', 'lat' => 50.9412, 'lng' => 6.9601]);
    $supermarket = Spot::factory()->create(['name' => 'REWE Domplatte', 'category' => 'supermarket', 'lat' => 50.9415, 'lng' => 6.9598]);
    $repository = app(CandidateRepository::class);

    $open = array_column($repository->candidatesFor(errandWindow([]), 50.941, 6.96), 'id');
    expect($open)->toContain("spot:{$museum->id}")
        ->not->toContain("spot:{$pharmacy->id}")
        ->not->toContain("spot:{$supermarket->id}");

    expect(array_column($repository->candidatesFor(errandWindow(['pharmacy']), 50.941, 6.96), 'id'))->toBe(["spot:{$pharmacy->id}"])
        ->and(array_column($repository->candidatesFor(errandWindow(['shopping']), 50.941, 6.96), 'id'))->toContain("spot:{$supermarket->id}");
});
