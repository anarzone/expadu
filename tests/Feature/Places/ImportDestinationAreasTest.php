<?php

use App\Models\Spot;
use App\Places\DestinationGrouping;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

test('the area importer attaches facilities to mapped parks and sports centres', function () {
    $park = Spot::factory()->create([
        'name' => 'Test Park',
        'category' => 'park',
        'source' => 'osm',
        'source_id' => 'way/101',
        'lat' => 50.9505,
        'lng' => 6.9505,
    ]);
    $sportsCentre = Spot::factory()->create([
        'name' => 'Sportanlage Test',
        'category' => 'sports_centre',
        'source' => 'osm',
        'source_id' => 'way/202',
        'lat' => 50.9605,
        'lng' => 6.9605,
    ]);
    $parkPitch = Spot::factory()->create([
        'name' => 'Bolzplatz',
        'category' => 'pitch',
        'lat' => 50.9504,
        'lng' => 6.9504,
    ]);
    $centreCourt = Spot::factory()->create([
        'name' => 'Tennisplatz',
        'category' => 'tennis',
        'lat' => 50.9604,
        'lng' => 6.9604,
    ]);
    $nearbyButSeparate = Spot::factory()->create([
        'name' => 'Separate basketball court',
        'category' => 'basketball',
        'lat' => 50.9704,
        'lng' => 6.9704,
    ]);

    Http::fake(['*' => Http::response(['elements' => [
        areaWay(101, 'Test Park', 'park', 50.95, 6.95),
        areaWay(202, 'Sportanlage Test', 'sports_centre', 50.96, 6.96),
    ]])]);

    $this->artisan('parks:import-areas')->assertSuccessful();

    expect($parkPitch->fresh())
        ->parent_spot_id->toBe($park->id)
        ->park_name->toBe('Test Park')
        ->and($centreCourt->fresh())
        ->parent_spot_id->toBe($sportsCentre->id)
        ->park_name->toBe('Sportanlage Test')
        ->and($nearbyButSeparate->fresh())
        ->parent_spot_id->toBeNull()
        ->and(DB::table('park_areas')->where('source_id', 'way/202')->value('kind'))
        ->toBe('sports_centre');
});

test('area import refresh preserves reviewed component and independent decisions', function (string $containment) {
    $park = Spot::factory()->create([
        'name' => 'Reviewed Park', 'category' => 'park',
        'source' => 'osm', 'source_id' => 'way/301',
        'lat' => 50.9505, 'lng' => 6.9505,
    ]);
    $otherPark = Spot::factory()->create([
        'name' => 'Other Park', 'category' => 'park',
        'source' => 'osm', 'source_id' => 'way/302',
        'lat' => 50.9605, 'lng' => 6.9605,
    ]);
    $component = Spot::factory()->create([
        'name' => 'Reviewed component court', 'category' => 'tennis',
        'lat' => 50.9504, 'lng' => 6.9504,
    ]);
    $independent = Spot::factory()->create([
        'name' => 'Independent museum', 'category' => 'museum',
        'lat' => 50.9506, 'lng' => 6.9506,
    ]);
    Http::fake(['*' => Http::sequence()
        ->push(['elements' => [
            areaWay(301, 'Reviewed Park', 'park', 50.95, 6.95),
            areaWay(302, 'Other Park', 'park', 50.96, 6.96),
        ]])
        ->push(['elements' => [
            areaWay(301, 'Reviewed Park refreshed', 'park', $containment === 'unchanged' ? 50.95 : 50.97, $containment === 'unchanged' ? 6.95 : 6.97),
            areaWay(302, 'Other Park', 'park', $containment === 'reassigned' ? 50.95 : 50.96, $containment === 'reassigned' ? 6.95 : 6.96),
        ]]),
    ]);
    $this->artisan('parks:import-areas')->assertSuccessful();
    expect($component->fresh()->parent_spot_id)->toBe($park->id)
        ->and($independent->fresh()->parent_spot_id)->toBe($park->id);

    $policy = app(DestinationGrouping::class);
    $this->travelTo(CarbonImmutable::parse('2026-09-10 10:00:00', 'UTC'));
    $componentEvidence = 'Reviewed facility source confirms operation as part of Reviewed Park.';
    $independentEvidence = 'Reviewed museum source confirms independent operation and public access.';
    $policy->review($component->id, $park->id, $componentEvidence, $policy->preview($component->id, $park->id)['fingerprint']);
    $policy->review($independent->id, null, $independentEvidence, $policy->preview($independent->id, null)['fingerprint']);
    $componentReviewedAt = $component->fresh()->getRawOriginal('destination_reviewed_at');
    $independentReviewedAt = $independent->fresh()->getRawOriginal('destination_reviewed_at');

    expect($policy->eligible(Spot::query())->whereKey($component->id)->exists())->toBeTrue()
        ->and($componentReviewedAt)->not->toBeNull()
        ->and($independentReviewedAt)->not->toBeNull();
    $this->travelTo(CarbonImmutable::parse('2026-09-11 10:00:00', 'UTC'));

    $this->artisan('parks:import-areas')->assertSuccessful();

    $expectedParent = match ($containment) {
        'unchanged' => $park->id,
        'reassigned' => $otherPark->id,
        'removed' => null,
    };
    $component->refresh();
    $independent->refresh();
    expect($component->parent_spot_id)->toBe($expectedParent)
        ->and($independent->parent_spot_id)->toBe($expectedParent)
        ->and($component->destination_spot_id)->toBe($park->id)
        ->and($independent->destination_spot_id)->toBeNull()
        ->and($component->destination_reviewed_parent_id)->toBe($park->id)
        ->and($independent->destination_reviewed_parent_id)->toBe($park->id)
        ->and($component->destination_grouping_evidence)->toBe($componentEvidence)
        ->and($independent->destination_grouping_evidence)->toBe($independentEvidence)
        ->and($component->getRawOriginal('destination_reviewed_at'))->toBe($componentReviewedAt)
        ->and($independent->getRawOriginal('destination_reviewed_at'))->toBe($independentReviewedAt)
        ->and($policy->eligible(Spot::query())->whereKey($component->id)->exists())->toBe($containment === 'unchanged')
        ->and($policy->general(Spot::query())->whereKey($independent->id)->exists())->toBeTrue()
        ->and(DB::table('park_areas')->where('source_id', 'way/301')->value('name'))->toBe('Reviewed Park refreshed')
        ->and(DB::table('place_destination_reviews')->count())->toBe(2);
})->with(['unchanged', 'reassigned', 'removed']);

/**
 * @return array<string, mixed>
 */
function areaWay(int $id, string $name, string $leisure, float $lat, float $lng): array
{
    return [
        'type' => 'way',
        'id' => $id,
        'tags' => ['name' => $name, 'leisure' => $leisure, 'sport' => 'tennis'],
        'geometry' => [
            ['lat' => $lat, 'lon' => $lng],
            ['lat' => $lat, 'lon' => $lng + 0.001],
            ['lat' => $lat + 0.001, 'lon' => $lng + 0.001],
            ['lat' => $lat + 0.001, 'lon' => $lng],
            ['lat' => $lat, 'lon' => $lng],
        ],
    ];
}
