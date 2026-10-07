<?php

use App\Models\Spot;
use App\Places\DestinationGrouping;
use App\Services\VeedelDirectory;
use Illuminate\Support\Facades\DB;

test('district directory counts destinations and independent venues without their component activities', function () {
    DB::table('veedels')->insert(['name' => 'Destination area', 'bezirk' => 'Destination district']);
    $park = Spot::factory()->create(['category' => 'park', 'veedel' => 'Destination area']);
    $policy = app(DestinationGrouping::class);
    foreach (['tennis', 'playground'] as $category) {
        $component = Spot::factory()->create([
            'category' => $category, 'veedel' => 'Destination area', 'parent_spot_id' => $park->id,
        ]);
        $policy->review($component->id, $park->id, 'Reviewed source confirms this facility operates as part of the destination.', $policy->preview($component->id, $park->id)['fingerprint']);
    }
    $museum = Spot::factory()->create([
        'category' => 'museum', 'veedel' => 'Destination area', 'parent_spot_id' => $park->id,
    ]);
    $policy->review($museum->id, null, 'Reviewed source confirms independent museum operation and public access.', $policy->preview($museum->id, null)['fingerprint']);

    $directory = app(VeedelDirectory::class);

    expect($directory->bezirkRail(null))->toBe([
        ['name' => 'Destination district', 'count' => 2, 'photo_url' => null],
    ])->and($directory->veedelsByBezirk())->toBe(['Destination district' => ['Destination area']]);
});

test('directory omits areas populated only by components of invalid destinations', function (string $invalidity) {
    DB::table('veedels')->insert(['name' => 'Component area', 'bezirk' => 'Component district']);
    $park = Spot::factory()->create(['category' => 'park', 'veedel' => null]);
    $component = Spot::factory()->create([
        'category' => 'tennis', 'veedel' => 'Component area', 'parent_spot_id' => $park->id,
    ]);
    $policy = app(DestinationGrouping::class);
    $policy->review($component->id, $park->id, 'Reviewed source confirms this facility operates as part of the destination.', $policy->preview($component->id, $park->id)['fingerprint']);
    match ($invalidity) {
        'inactive' => $park->update(['is_active' => false]),
        'restricted' => $park->update(['is_recommendable' => false]),
        'changed containment' => $component->update(['parent_spot_id' => null]),
    };

    $directory = app(VeedelDirectory::class);

    expect($directory->bezirkRail(null))->toBe([])
        ->and($directory->veedelsByBezirk())->toBe([]);
})->with(['inactive', 'restricted', 'changed containment']);
